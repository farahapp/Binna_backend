<?php

declare(strict_types=1);

namespace App\Audit;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class PlatformAuditEntry extends Model
{
    use HasUuids;

    protected $connection = 'mysql';

    protected $table = 'platform_audit_entries';

    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'event',
        'subject_type',
        'subject_id',
        'before',
        'after',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'created_at' => 'datetime',
    ];

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('Platform audit entries are immutable and cannot be updated.');
    }

    public function delete(): bool|null
    {
        throw new LogicException('Platform audit entries are immutable and cannot be deleted.');
    }

    public function forceDelete(): bool|null
    {
        throw new LogicException('Platform audit entries are immutable and cannot be deleted.');
    }
}
