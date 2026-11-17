<?php

declare(strict_types=1);

namespace App\Registration;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegistrationAttachmentService
{
    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    public const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    public const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly RegistrationAuditService $audit) {}

    public function store(
        UploadedFile $file,
        TenantRegistrationRequest $registration,
        string $actorType,
        int|string $actorId,
        ?string $documentType = null,
    ): TenantRegistrationAttachment {
        $this->assertSafeUpload($file);

        $documentType = $documentType !== null && $documentType !== ''
            ? Str::lower(trim($documentType))
            : null;

        if ($documentType !== null) {
            $accepted = $registration->attachments()
                ->where('document_type', $documentType)
                ->where('is_current', true)
                ->where('review_status', 'accepted')
                ->exists();
            if ($accepted) {
                // New version replaces accepted copy; prior accepted row is superseded for review.
            }
        }

        $detectedMime = $this->detectMime($file);
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs(
            'tenant-registrations/'.$registration->id,
            Str::uuid().'.'.$extension,
            'registration_private',
        );

        $replacesId = null;
        if ($documentType !== null) {
            $previous = $registration->attachments()
                ->where('document_type', $documentType)
                ->where('is_current', true)
                ->orderByDesc('id')
                ->first();
            if ($previous) {
                $replacesId = $previous->id;
                $previous->is_current = false;
                $previous->save();
            }
        }

        $attachment = $registration->attachments()->create([
            'request_id' => $registration->id,
            'document_type' => $documentType,
            'uploaded_by_type' => $actorType,
            'uploaded_by_id' => $actorId,
            'disk' => 'registration_private',
            'path' => $path,
            'original_name' => basename($file->getClientOriginalName()),
            'mime_type' => $detectedMime,
            'size' => $file->getSize(),
            'review_status' => 'pending',
            'review_note' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'replaces_attachment_id' => $replacesId,
            'is_current' => true,
        ]);

        $this->audit->record(
            $registration->id,
            $actorType,
            $actorId,
            'attachment.uploaded',
            $registration->status,
            $registration->status,
            null,
            [
                'attachment_id' => $attachment->id,
                'document_type' => $documentType,
                'replaces_attachment_id' => $replacesId,
                'mime_type' => $detectedMime,
                'size' => $attachment->size,
            ],
        );

        return $attachment;
    }

    public function download(TenantRegistrationAttachment $attachment): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        return $disk->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function assertSafeUpload(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                'file' => ['الملف غير صالح. أعد المحاولة بملف PDF أو صورة JPG/PNG.'],
            ]);
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'file' => ['حجم الملف يتجاوز الحد المسموح (10 ميغابايت).'],
            ]);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => ['امتداد الملف غير مسموح. المسموح: PDF، JPG، PNG.'],
            ]);
        }

        $path = $file->getRealPath() ?: $file->getPathname();
        $head = is_string($path) && $path !== ''
            ? (@file_get_contents($path, false, null, 0, 16) ?: '')
            : '';

        // Block executable-looking payloads mislabeled as images/pdf.
        if (str_starts_with($head, 'MZ') || str_starts_with($head, "\x7fELF")) {
            throw ValidationException::withMessages([
                'file' => ['تم رفض الملف: محتوى تنفيذي غير مسموح.'],
            ]);
        }

        $mime = $this->detectMime($file, $head);
        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw ValidationException::withMessages([
                'file' => ['نوع الملف الحقيقي غير مسموح. يُقبل فقط PDF وJPG وPNG.'],
            ]);
        }
    }

    private function detectMime(UploadedFile $file, string $head = ''): string
    {
        if (str_starts_with($head, '%PDF')) {
            return 'application/pdf';
        }
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }

        $path = $file->getRealPath() ?: $file->getPathname();
        if (is_string($path) && $path !== '' && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($detected) && in_array($detected, self::ALLOWED_MIME_TYPES, true)) {
                    return $detected;
                }
            }
        }

        $fallback = $file->getMimeType() ?? 'application/octet-stream';

        return in_array($fallback, self::ALLOWED_MIME_TYPES, true)
            ? $fallback
            : $fallback;
    }
}
