<?php

declare(strict_types=1);

namespace App\Foundation;

/**
 * TenantContext — immutable value object representing the resolved tenant
 * for the current request.
 *
 * Resolved by TenantContextResolver from the database; never constructed
 * from client-supplied data.
 */
final class TenantContext
{
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $schemaName,
        public readonly string $status,
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Build from an Eloquent Tenant model.
     */
    public static function fromModel(Tenant $tenant): self
    {
        return new self(
            id: $tenant->id,
            slug: $tenant->slug,
            name: $tenant->name,
            schemaName: $tenant->schema_name,
            status: $tenant->status,
        );
    }
}
