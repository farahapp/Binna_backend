<?php
declare(strict_types=1);
namespace Tests\Unit\Notification;

use App\Notification\NotificationPreference;
use PHPUnit\Framework\TestCase;

class NotificationPreferenceModelTest extends TestCase
{
    public function test_preference_table_is_correct(): void
    {
        $model = new NotificationPreference();
        $this->assertSame('notification_preferences', $model->getTable());
    }

    public function test_preference_connection_is_tenant(): void
    {
        $model = new NotificationPreference();
        $this->assertSame('tenant', $model->getConnectionName());
    }

    public function test_preference_fillable_contains_required_fields(): void
    {
        $model = new NotificationPreference();
        $fillable = $model->getFillable();
        $this->assertContains('tenant_slug', $fillable);
        $this->assertContains('user_id', $fillable);
        $this->assertContains('notification_type', $fillable);
        $this->assertContains('channel', $fillable);
        $this->assertContains('enabled', $fillable);
    }
}
