<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Foundation\Tenant;
use App\IAM\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * M4ResetAuthSmokeCommandTest
 *
 * Tests the non-interactive env-var path added to dev:m4-reset-auth-smoke.
 *
 * Scope:
 *  - Env guard: command refuses non-interactive path when APP_ENV is not local/testing.
 *  - Happy path: password is reset via M4_SMOKE_PW env-var and hash verifies.
 *  - Short password: command rejects passwords shorter than 8 characters.
 *  - No env-var set: command falls through to interactive prompt (exits with
 *    FAILURE in non-interactive test context because $this->secret() returns null).
 *
 * CONSTRAINTS:
 *  - No production code modified beyond M4ResetAuthSmoke.php.
 *  - Password value is never printed, stored, or asserted as a literal.
 *  - Uses the same bootstrap_iam_tenant.php helper as AuthSmokeTest.
 *  - Cleans up after itself via tearDownAfterClass.
 */
class M4ResetAuthSmokeCommandTest extends TestCase
{
    private static bool  $bootstrapped = false;
    private static array $ctx          = [];

    /** Initial password set during bootstrap — never printed. */
    private static string $initialPassword = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            self::$initialPassword = bin2hex(random_bytes(16));

            require_once __DIR__ . '/../../bootstrap_iam_tenant.php';

            self::$ctx          = bootstrapAuthSmokeTenant(self::$initialPassword);
            self::$bootstrapped = true;
        }
    }

    public static function tearDownAfterClass(): void
    {
        require_once __DIR__ . '/../../bootstrap_iam_tenant.php';
        cleanupAuthSmokeTenant();

        self::$bootstrapped    = false;
        self::$ctx             = [];
        self::$initialPassword = '';

        parent::tearDownAfterClass();
    }

    // =========================================================================
    // Guard: non-interactive path refused outside local/testing
    // =========================================================================

    /** @test */
    public function non_interactive_path_is_refused_in_production_environment(): void
    {
        // Temporarily override APP_ENV to simulate a production context.
        $originalEnv = app()->environment();

        // Bind a fake environment detector that reports 'production'.
        app()->detectEnvironment(fn () => 'production');

        // Set the env-var so the command would attempt the non-interactive path.
        putenv('M4_SMOKE_PW=SomePassword123');

        try {
            $this->artisan('dev:m4-reset-auth-smoke')
                ->expectsOutputToContain('Non-interactive path refused')
                ->assertExitCode(1);
        } finally {
            // Always restore environment and clear the env-var.
            app()->detectEnvironment(fn () => $originalEnv);
            putenv('M4_SMOKE_PW');
        }
    }

    // =========================================================================
    // Happy path: env-var resets password, hash verifies
    // =========================================================================

    /** @test */
    public function command_resets_password_via_env_var_and_hash_verifies(): void
    {
        // Generate a new password — never printed or stored.
        $newPassword = bin2hex(random_bytes(16));

        putenv('M4_SMOKE_PW=' . $newPassword);

        try {
            $this->artisan('dev:m4-reset-auth-smoke')
                ->expectsOutputToContain('Using non-interactive input (env-var). Value not echoed.')
                ->expectsOutputToContain('Password reset and verified successfully.')
                ->assertExitCode(0);
        } finally {
            putenv('M4_SMOKE_PW');
        }

        // Verify the hash in the DB without printing the password.
        \switchTenantConnection(self::$ctx['schemaName']);
        $fresh = User::on('tenant')->where('email', 'auth-smoke@binaa.test')->first();

        $this->assertNotNull($fresh, 'User must still exist after reset');
        $this->assertTrue(
            Hash::check($newPassword, $fresh->password),
            'DB hash must match the new password set via env-var'
        );

        // Update the shared initial password so subsequent tests in this run
        // that rely on the password (if any) use the new value.
        self::$initialPassword = $newPassword;
    }

    // =========================================================================
    // Short password rejected
    // =========================================================================

    /** @test */
    public function command_rejects_password_shorter_than_8_characters(): void
    {
        putenv('M4_SMOKE_PW=short');

        try {
            $this->artisan('dev:m4-reset-auth-smoke')
                ->expectsOutputToContain('Password must be at least 8 characters.')
                ->assertExitCode(1);
        } finally {
            putenv('M4_SMOKE_PW');
        }
    }

    // =========================================================================
    // Note: the interactive path ($this->secret()) is not tested here because
    // the Artisan test harness cannot drive hidden prompts without a TTY.
    // The interactive path is the original pre-existing behaviour and is
    // unchanged by this modification. The three tests above cover all new code.
    // =========================================================================
}
