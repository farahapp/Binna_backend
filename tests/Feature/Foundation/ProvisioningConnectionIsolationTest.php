<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Foundation\ProvisioningConnectionAccess;
use App\Foundation\ProvisioningMySqlConnection;
use App\Foundation\TenantProvisioner;
use App\Console\Commands\TenantProvisionRegistration;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\TestCase;

class ProvisioningConnectionIsolationTest extends TestCase
{
    public function test_provisioning_profile_is_not_a_general_database_connection(): void
    {
        $this->assertIsArray(config('database.provisioning_connection'));
        $this->assertSame('mysql_provisioning', config('database.provisioning_connection.driver'));
        $this->assertArrayNotHasKey('mysql_provisioning', config('database.connections'));
    }

    public function test_registration_command_scope_can_resolve_the_guarded_connection_and_revokes_it_afterward(): void
    {
        $connection = null;
        $this->withArgvCommand('tenant:provision-registration', function () use (&$connection): void {
            $this->app->make(ProvisioningConnectionAccess::class)->runForRegistrationCommand(
                function () use (&$connection): void {
                    $connection = DB::connection('mysql_provisioning');
                    $this->assertInstanceOf(ProvisioningMySqlConnection::class, $connection);
                },
            );
        });

        $this->assertArrayNotHasKey('mysql_provisioning', config('database.connections'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Provisioning database access is restricted');
        $connection->getRawPdo();
    }

    public function test_registration_command_handler_enters_the_authorized_scope(): void
    {
        $command = (new \ReflectionClass(TenantProvisionRegistration::class))->newInstanceWithoutConstructor();
        $access = Mockery::mock(ProvisioningConnectionAccess::class);
        $access->shouldReceive('runForRegistrationCommand')
            ->once()
            ->with(Mockery::on(static fn ($callback): bool => is_callable($callback)))
            ->andReturn(0);

        $this->assertSame(
            0,
            $command->handle(Mockery::mock(TenantProvisioner::class), $access),
        );
    }

    public function test_http_and_other_cli_contexts_cannot_open_the_provisioning_connection(): void
    {
        config(['database.connections.mysql_provisioning' => config('database.provisioning_connection')]);

        foreach (['queue:work', 'schedule:run', 'migrate', 'route:list'] as $command) {
            $this->withArgvCommand($command, function (): void {
                try {
                    DB::connection('mysql_provisioning');
                    $this->fail('A non-provisioning command must not resolve the connection.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('restricted to the tenant:provision-registration', $exception->getMessage());
                }
            });
            DB::purge('mysql_provisioning');
        }

        $connections = config('database.connections');
        unset($connections['mysql_provisioning']);
        config(['database.connections' => $connections]);

        $httpGuard = new class extends ProvisioningConnectionAccess {
            protected function isAuthorizedCommandInvocation(): bool
            {
                return false;
            }
        };
        $this->withArgvCommand('tenant:provision-registration', function () use ($httpGuard): void {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('restricted to the tenant:provision-registration');
            $httpGuard->runForRegistrationCommand(static fn () => null);
        });
    }

    public function test_direct_service_and_connection_access_cannot_bypass_the_guard(): void
    {
        config(['database.connections.mysql_provisioning' => config('database.provisioning_connection')]);

        try {
            $this->app->make(TenantProvisioner::class)->provisionRegistration(1);
            $this->fail('Direct service invocation must be rejected before database access.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('restricted to the tenant:provision-registration', $exception->getMessage());
        } finally {
            DB::purge('mysql_provisioning');
            $connections = config('database.connections');
            unset($connections['mysql_provisioning']);
            config(['database.connections' => $connections]);
        }

        $this->assertSame('mysql', DB::connection('mysql')->getConfig('driver'));
    }

    public function test_authorization_is_not_available_inside_queue_jobs_or_scheduled_tasks(): void
    {
        $this->withArgvCommand('tenant:provision-registration', function (): void {
            $this->app->make(ProvisioningConnectionAccess::class)->runForRegistrationCommand(function (): void {
                $job = Mockery::mock(Job::class);
                $job->shouldReceive('payload')->andReturn([]);
                $this->app['events']->dispatch(new JobProcessing('sync', $job));

                try {
                    DB::connection('mysql_provisioning');
                    $this->fail('Queue job execution must not inherit provisioning authorization.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('restricted to the tenant:provision-registration', $exception->getMessage());
                }

                $this->app['events']->dispatch(new JobProcessed('sync', $job));
                $this->assertInstanceOf(
                    ProvisioningMySqlConnection::class,
                    DB::connection('mysql_provisioning'),
                );
                DB::purge('mysql_provisioning');

                $input = Mockery::mock(InputInterface::class);
                $output = Mockery::mock(OutputInterface::class);
                $this->app['events']->dispatch(new CommandStarting('migrate', $input, $output));
                try {
                    DB::connection('mysql_provisioning');
                    $this->fail('A nested Artisan command must not inherit provisioning authorization.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('restricted to the tenant:provision-registration', $exception->getMessage());
                } finally {
                    $this->app['events']->dispatch(new CommandFinished('migrate', $input, $output, 0));
                }
                $this->assertInstanceOf(
                    ProvisioningMySqlConnection::class,
                    DB::connection('mysql_provisioning'),
                );
                DB::purge('mysql_provisioning');

                $failedJob = Mockery::mock(Job::class);
                $failedJob->shouldReceive('payload')->andReturn([]);
                $this->app['events']->dispatch(new JobProcessing('sync', $failedJob));
                $this->app['events']->dispatch(
                    new JobExceptionOccurred('sync', $failedJob, new RuntimeException('job failure')),
                );
                $this->assertInstanceOf(
                    ProvisioningMySqlConnection::class,
                    DB::connection('mysql_provisioning'),
                );
                DB::purge('mysql_provisioning');

                $task = Mockery::mock(\Illuminate\Console\Scheduling\Event::class);
                $this->app['events']->dispatch(new ScheduledTaskStarting($task));
                try {
                    DB::connection('mysql_provisioning');
                    $this->fail('Scheduled task execution must not inherit provisioning authorization.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('restricted to the tenant:provision-registration', $exception->getMessage());
                } finally {
                    $this->app['events']->dispatch(new \Illuminate\Console\Events\ScheduledTaskFinished($task, 0.0));
                }
            });
        });
    }

    public function test_access_denial_does_not_disclose_connection_configuration(): void
    {
        config([
            'database.connections.mysql_provisioning' => array_merge(
                config('database.provisioning_connection'),
                [
                    'host' => 'private-host-marker',
                    'username' => 'private-user-marker',
                    'password' => 'private-password-marker',
                ],
            ),
        ]);

        try {
            DB::connection('mysql_provisioning');
            $this->fail('Connection must be rejected outside the authorized scope.');
        } catch (RuntimeException $exception) {
            foreach (['private-host-marker', 'private-user-marker', 'private-password-marker'] as $secret) {
                $this->assertStringNotContainsString($secret, $exception->getMessage());
            }
        } finally {
            DB::purge('mysql_provisioning');
            $connections = config('database.connections');
            unset($connections['mysql_provisioning']);
            config(['database.connections' => $connections]);
        }
    }

    private function withArgvCommand(string $command, callable $callback): mixed
    {
        $hadArgv = array_key_exists('argv', $_SERVER);
        $previousArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', $command];

        try {
            return $callback();
        } finally {
            if ($hadArgv) {
                $_SERVER['argv'] = $previousArgv;
            } else {
                unset($_SERVER['argv']);
            }
        }
    }
}
