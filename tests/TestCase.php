<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every feature test uses isolated SQLite in-memory connections. The
     * project phpunit.xml targets a dedicated MySQL test service; this local
     * checkpoint must never contact that service or any shared database.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        // Opt into MySQL only when the caller has provided a dedicated,
        // isolated test server. Default stays in-memory SQLite to prevent an
        // accidental connection to project or Production database settings.
        if (filter_var(env('BINAA2_TEST_ISOLATED_MYSQL', false), FILTER_VALIDATE_BOOL)) {
            return $app;
        }

        $sqlite = $app['config']->get('database.connections.sqlite');
        $sqlite['database'] = ':memory:';
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql', $sqlite);
        $app['config']->set('database.connections.tenant', $sqlite);

        return $app;
    }

    /**
     * Connections to wrap in a database transaction for each test.
     *
     * RefreshDatabase's connectionsToTransact() checks for this property and
     * uses it when present (see Illuminate\Foundation\Testing\RefreshDatabase).
     * Including 'tenant' ensures that writes to the per-tenant connection are
     * rolled back between tests, preventing cross-test data leakage.
     *
     * In the test environment both 'mysql' and 'tenant' point to binaa2_testing
     * (TENANT_DB_DATABASE=binaa2_testing in phpunit.xml), so this is safe.
     *
     * Tests that override connectionsToTransact() as a method (e.g.
     * FinanceAccountingIntegrationTest) take precedence over this property
     * because the trait's connectionsToTransact() checks property_exists()
     * first, but method overrides in subclasses shadow the trait method entirely.
     *
     * Authorization: CRM-ISOLATION-FIX — 2026-10-10
     */
    protected array $connectionsToTransact = ['mysql', 'tenant'];
}
