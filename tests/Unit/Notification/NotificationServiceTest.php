<?php
declare(strict_types=1);
namespace Tests\Unit\Notification;

use App\Notification\NotificationService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class NotificationServiceTest extends TestCase
{
    public function test_send_throws_on_empty_tenant_slug(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $dispatcher = $this->createMock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $service = new NotificationService($dispatcher);

        $service->send('', 'user-id', 'App\IAM\User', 'test.type');
    }

    public function test_send_throws_on_empty_notifiable_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $dispatcher = $this->createMock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $service = new NotificationService($dispatcher);

        $service->send('tenant', '', 'App\IAM\User', 'test.type');
    }

    public function test_send_throws_on_empty_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $dispatcher = $this->createMock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $service = new NotificationService($dispatcher);

        $service->send('tenant', 'user-id', 'App\IAM\User', '');
    }
}
