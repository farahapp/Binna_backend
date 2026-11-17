<?php

declare(strict_types=1);

namespace App\Foundation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Registration\TenantRegistrationRequest;

class TenantIdentifier extends Model
{
    public const TYPES = [
        'cr',
        'establishment',
        'commercial_license',
        'professional_license',
        'engineering_consultancy_registration',
        'qid',
    ];

    public const VALIDITY_STATUSES = ['unverified', 'valid', 'expired', 'invalid'];

    public static function loginTypeForLegalType(?string $legalType): ?string
    {
        return match ($legalType) {
            'organization' => 'cr',
            'individual' => 'qid',
            default => null,
        };
    }

    public static function hasValidLoginIdentifierForTenant(Tenant $tenant): bool
    {
        $requiredType = self::loginTypeForLegalType($tenant->legal_type);
        if ($requiredType === null) {
            return false;
        }

        return self::on('mysql')
            ->where('tenant_id', $tenant->id)
            ->where('type', $requiredType)
            ->where('validity_status', 'valid')
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhereDate('expires_at', '>=', now()->toDateString());
            })
            ->exists();
    }

    protected $connection = 'mysql';

    protected $table = 'tenant_identifiers';

    protected $fillable = [
        'tenant_id',
        'registration_request_id',
        'type',
        'number',
        'expires_at',
        'validity_status',
        'document_path',
    ];

    protected $hidden = ['normalized_number', 'document_path'];

    protected $casts = [
        'expires_at' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $identifier): void {
            $identifier->type = strtolower(trim($identifier->type));
            $identifier->number = trim($identifier->number);
            $identifier->normalized_number = self::normalize($identifier->number);
        });
    }

    public static function normalize(string $number): string
    {
        $number = mb_strtoupper(trim($number));
        $number = strtr($number, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        return preg_replace('/[^A-Z0-9]/u', '', $number) ?? '';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function registrationRequest(): BelongsTo
    {
        return $this->belongsTo(TenantRegistrationRequest::class, 'registration_request_id');
    }
}
