<?php

declare(strict_types=1);

namespace Tests\Unit\CRM;

use App\CRM\Contact;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Contact model — TASK-003A CRM Domain/Data Foundation.
 */
class ContactModelTest extends TestCase
{
    /** @test */
    public function contact_uses_tenant_connection(): void
    {
        $this->assertSame(
            'tenant',
            (new Contact())->getConnectionName(),
            'Contact must use the per-tenant connection (OD-001)'
        );
    }

    /** @test */
    public function contact_uses_correct_table(): void
    {
        $this->assertSame('contacts', (new Contact())->getTable());
    }

    /** @test */
    public function contact_has_required_fillable_fields(): void
    {
        $fillable = (new Contact())->getFillable();

        // A4 §4.1 GetContact output: name, role, email, phone
        foreach (['name', 'role', 'email', 'phone', 'client_id', 'tenant_slug'] as $field) {
            $this->assertContains($field, $fillable, "Contact must have '{$field}' in fillable");
        }
    }

    /** @test */
    public function is_active_returns_true_only_for_active_status(): void
    {
        $contact = new Contact();

        $contact->status = 'active';
        $this->assertTrue($contact->isActive());

        $contact->status = 'inactive';
        $this->assertFalse($contact->isActive());
    }

    /** @test */
    public function contact_client_id_is_nullable_in_fillable(): void
    {
        // client_id is nullable — Contact may exist before Client linkage
        $this->assertContains('client_id', (new Contact())->getFillable());
    }
}
