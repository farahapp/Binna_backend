<?php
declare(strict_types=1);
namespace App\Document;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DocumentCategory — Per-tenant document category.
 *
 * Supports hierarchical categories via parent_id (self-referential).
 * F-13 (UNRESOLVED): UUID follows HasUuids convention.
 */
class DocumentCategory extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'document_categories';

    protected $fillable = [
        'tenant_slug',
        'name',
        'description',
        'parent_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'category_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(DocumentCategory::class, 'parent_id');
    }
}
