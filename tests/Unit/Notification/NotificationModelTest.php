<?php
declare(strict_types=1);
namespace Tests\Unit\Notification;

use App\Notification\Notification;
use PHPUnit\Framework\TestCase;

class NotificationModelTest extends TestCase
{
    public function test_notification_table_is_correct(): void
    {
        $model = new Notification();
        $this->assertSame('notifications', $model->getTable());
    }

    public function test_notification_connection_is_tenant(): void
    {
        $model = new Notification();
        $this->assertSame('tenant', $model->getConnectionName());
    }

    public function test_notification_fillable_contains_required_fields(): void
    {
        $model = new Notification();
        $fillable = $model->getFillable();
        $this->assertContains('tenant_slug', $fillable);
        $this->assertContains('notifiable_id', $fillable);
        $this->assertContains('notifiable_type', $fillable);
        $this->assertContains('type', $fillable);
        $this->assertContains('channel', $fillable);
        $this->assertContains('data', $fillable);
        $this->assertContains('status', $fillable);
    }

    public function test_is_read_returns_false_when_read_at_is_null(): void
    {
        $notification = new Notification();
        $notification->read_at = null;
        $this->assertFalse($notification->isRead());
    }
}
