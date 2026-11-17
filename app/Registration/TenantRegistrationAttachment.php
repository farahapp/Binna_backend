<?php

declare(strict_types=1);

namespace App\Registration;

use Illuminate\Database\Eloquent\Model;

class TenantRegistrationAttachment extends Model
{
    protected $connection = 'mysql';

    protected $table = 'tenant_registration_attachments';

    protected $fillable = [
        'request_id',
        'document_type',
        'uploaded_by_type',
        'uploaded_by_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'review_status',
        'review_note',
        'reviewed_by',
        'reviewed_at',
        'replaces_attachment_id',
        'is_current',
    ];

    protected $hidden = ['disk', 'path'];

    protected $casts = [
        'is_current' => 'boolean',
        'reviewed_at' => 'datetime',
        'size' => 'integer',
    ];
}
