<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Audit\PlatformAuditEntry;
use App\Foundation\PlatformUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Tests\TestCase;

/**
 * Security contract for interactive bootstrap secrets (no argv passwords).
 * Uses in-memory SQLite only — never Production / shared MySQL.
 */
class BootstrapPlatformOwnerSecurityTest extends TestCase
{
    private const BOOTSTRAP_SECRET = 'e8-test-bootstrap-secret-value';

    private const OWNER_PASSWORD = 'interactive-owner-password-12';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.mysql.driver' => 'sqlite',
            'database.connections.mysql.database' => ':memory:',
            'database.connections.mysql.prefix' => '',
            'database.connections.mysql.foreign_key_constraints' => true,
        ]);
        DB::purge('mysql');

        Schema::connection('mysql')->create('platform_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::connection('mysql')->create('platform_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
        Schema::connection('mysql')->create('platform_permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('description');
            $table->timestamps();
        });
        Schema::connection('mysql')->create('platform_role_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('platform_role_id');
            $table->unsignedBigInteger('platform_permission_id');
            $table->primary(['platform_role_id', 'platform_permission_id']);
        });
        Schema::connection('mysql')->create('platform_user_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('platform_user_id');
            $table->unsignedBigInteger('platform_role_id');
            $table->primary(['platform_user_id', 'platform_role_id']);
        });
        Schema::connection('mysql')->create('platform_audit_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('event', 100)->index();
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 100)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        $now = now();
        DB::connection('mysql')->table('platform_permissions')->insert([
            'name' => 'platform.users.view',
            'description' => 'platform.users.view',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        putenv('PLATFORM_BOOTSTRAP_SECRET='.self::BOOTSTRAP_SECRET);
        $_ENV['PLATFORM_BOOTSTRAP_SECRET'] = self::BOOTSTRAP_SECRET;
        $_SERVER['PLATFORM_BOOTSTRAP_SECRET'] = self::BOOTSTRAP_SECRET;
    }

    protected function tearDown(): void
    {
        putenv('PLATFORM_BOOTSTRAP_SECRET');
        unset($_ENV['PLATFORM_BOOTSTRAP_SECRET'], $_SERVER['PLATFORM_BOOTSTRAP_SECRET']);
        DB::purge('mysql');
        parent::tearDown();
    }

    #[Test]
    public function command_signature_rejects_password_and_secret_options(): void
    {
        $definition = $this->app->make(\Illuminate\Contracts\Console\Kernel::class)
            ->all()['platform:iam:bootstrap-owner']
            ->getDefinition();

        $this->assertFalse($definition->hasOption('password'));
        $this->assertFalse($definition->hasOption('secret'));

        try {
            $this->artisan('platform:iam:bootstrap-owner', [
                'email' => 'owner@example.test',
                '--create' => true,
                '--confirm' => true,
                '--name' => 'Owner',
                '--password' => self::OWNER_PASSWORD,
            ]);
            $this->fail('Expected InvalidOptionException for --password');
        } catch (InvalidOptionException $e) {
            $this->assertStringContainsString('password', strtolower($e->getMessage()));
        }

        try {
            $this->artisan('platform:iam:bootstrap-owner', [
                'email' => 'owner@example.test',
                '--create' => true,
                '--confirm' => true,
                '--name' => 'Owner',
                '--secret' => self::BOOTSTRAP_SECRET,
            ]);
            $this->fail('Expected InvalidOptionException for --secret');
        } catch (InvalidOptionException $e) {
            $this->assertStringContainsString('secret', strtolower($e->getMessage()));
        }
    }

    #[Test]
    public function create_mode_accepts_interactive_secret_and_password_without_leaking_to_audit_or_logs(): void
    {
        $this->artisan('platform:iam:bootstrap-owner', [
            'email' => 'first-owner@example.test',
            '--create' => true,
            '--confirm' => true,
            '--name' => 'First Owner',
        ])
            ->expectsQuestion('Bootstrap secret (hidden)', self::BOOTSTRAP_SECRET)
            ->expectsQuestion('Owner password (min 12, hidden)', self::OWNER_PASSWORD)
            ->assertExitCode(0);

        $consoleOutput = Artisan::output();
        $this->assertStringNotContainsString(self::OWNER_PASSWORD, $consoleOutput);
        $this->assertStringNotContainsString(self::BOOTSTRAP_SECRET, $consoleOutput);

        $user = PlatformUser::on('mysql')->where('email', 'first-owner@example.test')->firstOrFail();
        $this->assertTrue(Hash::check(self::OWNER_PASSWORD, $user->password));
        $this->assertTrue($user->platformRoles()->where('slug', 'platform-owner')->exists());

        $audit = PlatformAuditEntry::on('mysql')
            ->where('event', 'platform.iam.owner_bootstrapped')
            ->firstOrFail();
        $payload = json_encode($audit->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::OWNER_PASSWORD, $payload);
        $this->assertStringNotContainsString(self::BOOTSTRAP_SECRET, $payload);
        $this->assertSame('first-owner@example.test', $audit->after['email'] ?? null);
    }

    #[Test]
    public function create_mode_rejects_wrong_secret_with_generic_message(): void
    {
        $this->artisan('platform:iam:bootstrap-owner', [
            'email' => 'owner@example.test',
            '--create' => true,
            '--confirm' => true,
            '--name' => 'Owner',
        ])
            ->expectsQuestion('Bootstrap secret (hidden)', 'wrong-secret-value')
            ->expectsOutput('Invalid or missing PLATFORM_BOOTSTRAP_SECRET / bootstrap secret.')
            ->assertExitCode(1);

        $this->assertSame(0, PlatformUser::on('mysql')->count());
        $this->assertSame(0, PlatformAuditEntry::on('mysql')->count());
    }

    #[Test]
    public function create_mode_rejects_when_platform_user_already_exists(): void
    {
        PlatformUser::on('mysql')->create([
            'name' => 'Existing',
            'email' => 'existing@example.test',
            'password' => 'existing-password-12',
            'is_active' => true,
        ]);

        $this->artisan('platform:iam:bootstrap-owner', [
            'email' => 'another@example.test',
            '--create' => true,
            '--confirm' => true,
            '--name' => 'Another',
        ])
            ->expectsQuestion('Bootstrap secret (hidden)', self::BOOTSTRAP_SECRET)
            ->expectsQuestion('Owner password (min 12, hidden)', self::OWNER_PASSWORD)
            ->expectsOutput('Platform users already exist. Use email assignment mode (without --create).')
            ->assertExitCode(1);

        $this->assertSame(1, PlatformUser::on('mysql')->count());
        $this->assertDatabaseMissing('platform_audit_entries', [
            'event' => 'platform.iam.owner_bootstrapped',
        ], 'mysql');
    }

    #[Test]
    public function create_mode_rejects_when_owner_already_assigned(): void
    {
        $this->artisan('platform:iam:bootstrap-owner', [
            'email' => 'first-owner@example.test',
            '--create' => true,
            '--confirm' => true,
            '--name' => 'First Owner',
        ])
            ->expectsQuestion('Bootstrap secret (hidden)', self::BOOTSTRAP_SECRET)
            ->expectsQuestion('Owner password (min 12, hidden)', self::OWNER_PASSWORD)
            ->assertExitCode(0);

        // Wipe users table count path: assignment mode already has owner role users.
        // Second --create must fail because users exist.
        $this->artisan('platform:iam:bootstrap-owner', [
            'email' => 'second@example.test',
            '--create' => true,
            '--confirm' => true,
            '--name' => 'Second',
        ])
            ->expectsQuestion('Bootstrap secret (hidden)', self::BOOTSTRAP_SECRET)
            ->expectsQuestion('Owner password (min 12, hidden)', self::OWNER_PASSWORD)
            ->assertExitCode(1);

        $this->assertSame(1, PlatformUser::on('mysql')->count());
    }

    #[Test]
    public function assignment_mode_still_requires_confirm_and_is_one_time(): void
    {
        $owner = PlatformUser::on('mysql')->create([
            'name' => 'Operator',
            'email' => 'operator@example.test',
            'password' => 'operator-password-12',
            'is_active' => true,
        ]);
        $other = PlatformUser::on('mysql')->create([
            'name' => 'Other',
            'email' => 'other@example.test',
            'password' => 'other-password-12',
            'is_active' => true,
        ]);

        $this->artisan('platform:iam:bootstrap-owner', [
            'email' => $owner->email,
        ])->assertExitCode(1);

        $this->artisan('platform:iam:bootstrap-owner', [
            'email' => $owner->email,
            '--confirm' => true,
        ])->assertExitCode(0);

        $this->assertTrue($owner->fresh()->platformRoles()->where('slug', 'platform-owner')->exists());

        $this->artisan('platform:iam:bootstrap-owner', [
            'email' => $other->email,
            '--confirm' => true,
        ])->assertExitCode(1);

        $this->assertFalse($other->fresh()->platformRoles()->exists());
    }
}
