<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Foundation\ProvisioningConnectionAccess;
use App\Foundation\Tenant;
use App\Foundation\TenantProvisioner;
use App\Registration\TenantRegistrationRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TenantProvisionRegistration extends Command
{
    protected $signature = 'tenant:provision-registration
                            {request : Approved tenant registration request ID}
                            {--confirm : Required in production}';

    protected $description = 'Manually provision a tenant from an approved registration';

    public function handle(
        TenantProvisioner $provisioner,
        ProvisioningConnectionAccess $connectionAccess,
    ): int {
        return $connectionAccess->runForRegistrationCommand(
            fn (): int => $this->provisionRegistration($provisioner),
        );
    }

    private function provisionRegistration(TenantProvisioner $provisioner): int
    {
        $requestId = filter_var($this->argument('request'), FILTER_VALIDATE_INT);
        if ($requestId === false || $requestId < 1) {
            $this->error('A valid registration request ID is required.');

            return self::FAILURE;
        }

        if (app()->environment('production') && ! $this->option('confirm')) {
            $this->error('Production provisioning requires --confirm.');

            return self::FAILURE;
        }

        $registration = TenantRegistrationRequest::on('mysql')->find($requestId);
        if (! $registration || $registration->status !== 'approved_pending_provisioning') {
            $this->error('The request does not exist or is not approved for provisioning.');

            return self::FAILURE;
        }

        $this->line("Provisioning approved registration {$registration->request_number}.");
        $this->line(
            'This command prepares the tenant schema and migrations, then verifies an external exact-schema runtime grant. '
            . 'Laravel does not execute GRANT; a missing grant leaves the tenant pending for operator recovery.'
        );
        try {
            $tenant = $provisioner->provisionRegistration($registration->id);
        } catch (RuntimeException $exception) {
            Log::error('Manual tenant registration provisioning failed.', [
                'registration_id' => $registration->id,
                'exception_class' => $exception::class,
            ]);
            if (str_contains($exception->getMessage(), 'GRANT verification failed')) {
                $schemaName = Tenant::deriveSchemaName($registration->slug);
                $this->error(
                    "External runtime privileges are missing or incomplete for {$schemaName}. "
                    . 'Laravel issued no GRANT. Keep this tenant pending; an authorized external MySQL operator '
                    . 'must apply the approved four Runtime DML privileges to this schema, then rerun this command.'
                );
            }
            $this->error('Provisioning failed; the tenant remains inactive. Inspect restricted operator logs.');

            return self::FAILURE;
        }

        $this->info("Tenant {$tenant->slug} is active.");

        return self::SUCCESS;
    }
}
