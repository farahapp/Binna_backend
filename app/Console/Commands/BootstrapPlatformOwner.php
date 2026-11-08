<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\PlatformAuditService;
use App\Foundation\PlatformPermission;
use App\Foundation\PlatformRole;
use App\Foundation\PlatformUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BootstrapPlatformOwner extends Command
{
    protected $signature = 'platform:iam:bootstrap-owner {email : Email of the existing active PlatformUser} {--confirm : Confirm the one-time Platform Owner bootstrap}';

    protected $description = 'Assign the Platform Owner role to one existing active platform operator';

    public function handle(PlatformAuditService $audit): int
    {
        if (! $this->option('confirm')) {
            $this->error('Pass --confirm after verifying the selected PlatformUser and the target environment.');

            return self::FAILURE;
        }

        $user = PlatformUser::on('mysql')->where('email', $this->argument('email'))->first();
        if (! $user || ! $user->isActive()) {
            $this->error('An existing active PlatformUser with that email is required.');

            return self::FAILURE;
        }

        $permissions = PlatformPermission::on('mysql')->get();
        if ($permissions->isEmpty()) {
            $this->error('Platform IAM permission catalog is missing. Apply the approved Platform IAM migration first.');

            return self::FAILURE;
        }
        if (! Schema::connection('mysql')->hasTable('platform_audit_entries')) {
            $this->error('Platform Audit schema is required before bootstrapping the Platform Owner.');

            return self::FAILURE;
        }

        try {
            DB::connection('mysql')->transaction(function () use ($audit, $permissions, $user): void {
                $role = PlatformRole::on('mysql')->where('slug', 'platform-owner')->lockForUpdate()->first();
                if ($role && ! $role->is_system) {
                    throw new \LogicException('The platform-owner slug is already assigned to a non-system role.');
                }
                $lockedUser = PlatformUser::on('mysql')->lockForUpdate()->find($user->getKey());
                if (! $lockedUser || ! $lockedUser->isActive()) {
                    throw new \LogicException('The selected PlatformUser must remain active during bootstrap.');
                }
                if ($role && $role->users()->exists()) {
                    throw new \LogicException('A Platform Owner is already assigned. Bootstrap is a one-time operation.');
                }

                if (! $role) {
                    $role = PlatformRole::on('mysql')->create([
                        'name' => 'Platform Owner',
                        'slug' => 'platform-owner',
                        'description' => 'System role with all Platform IAM permissions.',
                        'is_system' => true,
                    ]);
                }

                $role->permissions()->sync($permissions->modelKeys());
                $lockedUser->platformRoles()->syncWithoutDetaching([$role->getKey()]);

                $audit->record(
                    null,
                    'platform.iam.owner_bootstrapped',
                    'platform_user',
                    $lockedUser->getKey(),
                    null,
                    ['role_id' => $role->getKey(), 'initiated_by' => 'artisan'],
                );
            });
        } catch (\LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Platform Owner role assigned. No credentials or tokens were changed.');

        return self::SUCCESS;
    }
}
