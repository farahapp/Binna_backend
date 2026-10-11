<?php
declare(strict_types=1);
namespace Tests\Unit\Notification;

use App\Notification\Notification;
use App\Notification\Providers\NullNotificationProvider;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\TestCase;

class NullNotificationProviderTest extends TestCase
{
    public function test_channel_returns_database(): void
    {
        $provider = new NullNotificationProvider();
        $this->assertSame('database', $provider->channel());
    }

    public function test_deliver_returns_true(): void
    {
        // Swap the Log facade to a spy so no real facade root is needed.
        Log::swap(new class {
            public function info(string $message, array $context = []): void {}
        });

        $provider = new NullNotificationProvider();
        $notification = new Notification();
        $notification->id = 'test-uuid';
        $notification->type = 'test.type';
        $notification->channel = 'database';
        $notification->notifiable_id = 'user-uuid';
        $notification->notifiable_type = 'App\IAM\User';

        $result = $provider->deliver($notification, 'test-tenant');
        $this->assertTrue($result);
    }
}
