<?php
declare(strict_types=1);
namespace Tests\Unit\Notification;

use App\Notification\Jobs\SendNotificationJob;
use PHPUnit\Framework\TestCase;

class SendNotificationJobTest extends TestCase
{
    public function test_job_carries_notification_id_and_tenant_slug(): void
    {
        $job = new SendNotificationJob(
            notificationId: 'notif-uuid-123',
            tenantSlug: 'acme-corp',
        );

        $this->assertSame('notif-uuid-123', $job->notificationId);
        $this->assertSame('acme-corp', $job->tenantSlug);
    }

    public function test_job_implements_should_queue(): void
    {
        $job = new SendNotificationJob('id', 'slug');
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $job);
    }
}
