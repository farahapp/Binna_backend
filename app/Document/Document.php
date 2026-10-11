<?php
declare(strict_types=1);
namespace App\Document;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Document — Per-tenant document record (metadata only).
 *
 * TASK-002C: Metadata only. storage_path is a nullable placeholder
 * for physical file storage (TASK-002D).
 *
 * F-13 (UNRESOLVED): UUID follows HasUuids convention.
 */
class Document extends Model
{
    use HasUuids, SoftDeletes;

    protected $connection = 'tenant';
    protected $table = 'documents';

    protected $fillable = [
        'tenant_slug',
        'category_id',
        'title',
        'description',
        'document_number',
        'status',
        'created_by',
        'mime_type',
        'file_size',
        'storage_path',
        'current_version',
        'tags',
    ];

    protected $casts = [
        'tags'            => 'array',
        'file_size'       => 'integer',
        'current_version' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'category_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class, 'document_id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
