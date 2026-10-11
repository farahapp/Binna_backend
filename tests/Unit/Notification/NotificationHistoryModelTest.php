<?php
declare(strict_types=1);
namespace Tests\Unit\Notification;

use App\Notification\NotificationHistory;
use LogicException;
use PHPUnit\Framework\TestCase;

class NotificationHistoryModelTest extends TestCase
{
    public function test_history_table_is_correct(): void
    {
        $model = new NotificationHistory();
        $this->assertSame('notification_history', $model->getTable());
    }

    public function test_history_connection_is_tenant(): void
    {
        $model = new NotificationHistory();
        $this->assertSame('tenant', $model->getConnectionName());
    }

    public function test_history_is_immutable_update_throws(): void
    {
        $this->expectException(LogicException::class);
        $model = new NotificationHistory();
        $model->update(['status' => 'changed']);
    }

    public function test_history_is_immutable_delete_throws(): void
    {
        $this->expectException(LogicException::class);
        $model = new NotificationHistory();
        $model->delete();
    }
}
