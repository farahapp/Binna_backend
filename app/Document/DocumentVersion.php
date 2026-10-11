<?php
declare(strict_types=1);
namespace App\Document;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * DocumentVersion — Per-tenant immutable version record (metadata only).
 *
 * Version records are immutable once created — they represent a point-in-time
 * snapshot of a document's metadata.
 *
 * TASK-002C: Metadata only. storage_path is a nullable placeholder
 * for physical file storage (TASK-002D).
 *
 * F-13 (UNRESOLVED): UUID follows HasUuids convention.
 */
class DocumentVersion extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'document_versions';

    protected $fillable = [
        'tenant_slug',
        'document_id',
        'version_number',
        'change_summary',
        'created_by',
        'mime_type',
        'file_size',
        'storage_path',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'file_size'      => 'integer',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /**
     * Version records are immutable — prevent updates.
     *
     * @throws LogicException
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('DocumentVersion records are immutable and cannot be updated.');
    }

    /**
     * Version records are immutable — prevent deletion.
     *
     * @throws LogicException
     */
    public function delete(): bool|null
    {
        throw new LogicException('DocumentVersion records are immutable and cannot be deleted.');
    }
}
