<?php

use Illuminate\Support\Str;

/*
 * PDO MySQL SSL-CA constant compatibility shim.
 *
 * PHP 8.4 introduced the namespaced Pdo\Mysql::ATTR_SSL_CA constant and
 * deprecated the top-level PDO::MYSQL_ATTR_SSL_CA alias.
 * PHP 8.5 removes the deprecated alias entirely, causing a fatal error.
 *
 * This shim resolves the correct constant at config-load time so the file
 * works on PHP 8.3, 8.4, and 8.5+ without touching any other settings.
 */
if (! defined('BINAA2_PDO_MYSQL_ATTR_SSL_CA')) {
    // Use if/else — NOT a ternary — so that PDO::MYSQL_ATTR_SSL_CA is never
    // referenced (not even in a dead branch) on PHP 8.5, where the constant
    // was removed entirely and causes a fatal error at parse time in a ternary.
    //
    // Branch logic:
    //   PHP ≥ 8.4 + pdo_mysql loaded  → \Pdo\Mysql::ATTR_SSL_CA  (namespaced, no deprecation)
    //   PHP ≤ 8.3 + pdo_mysql loaded  → PDO::MYSQL_ATTR_SSL_CA   (legacy alias, safe on ≤8.3)
    //   pdo_mysql NOT loaded           → placeholder 0 (options array is [] anyway via extension_loaded check)
    if (extension_loaded('pdo_mysql') && class_exists(\Pdo\Mysql::class)) {
        define('BINAA2_PDO_MYSQL_ATTR_SSL_CA', \Pdo\Mysql::ATTR_SSL_CA);
    } elseif (extension_loaded('pdo_mysql')) {
        define('BINAA2_PDO_MYSQL_ATTR_SSL_CA', PDO::MYSQL_ATTR_SSL_CA); // PHP ≤ 8.3 only
    } else {
        define('BINAA2_PDO_MYSQL_ATTR_SSL_CA', 0); // pdo_mysql absent; options array is [] anyway
    }
}

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                BINAA2_PDO_MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        /*
         * Per-tenant MySQL connection — used by all tenant-aware models
         * (connection = 'tenant').
         *
         * In production the actual tenant database name is resolved dynamically
         * by TenantBaselineMigrator / TenantContextResolver. In testing this
         * connection is configured statically via TENANT_DB_DATABASE so that
         * RefreshDatabase and Schema::connection('tenant') work correctly.
         *
         * Falls back to DB_DATABASE when TENANT_DB_DATABASE is not set, which
         * makes single-DB test environments work out of the box.
         */
        'tenant' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('TENANT_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('TENANT_DB_PORT', env('DB_PORT', '3306')),
            'database' => env('TENANT_DB_DATABASE', env('DB_DATABASE', 'laravel')),
            'username' => env('TENANT_DB_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('TENANT_DB_PASSWORD', env('DB_PASSWORD', '')),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                BINAA2_PDO_MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        /*
         * DEC-MYSQL-001 — Provisioning-only connection.
         *
         * This connection is used EXCLUSIVELY by TenantProvisioner (via CLI) to:
         *   - CREATE DATABASE for new tenant schemas
         *   - GRANT exact per-schema privileges to the runtime user
         *
         * It MUST NOT be used by the application runtime (HTTP requests, jobs, queues).
         * The provisioning user requires: CREATE, GRANT OPTION on *.* (or specific schemas).
         * The runtime user (DB_USERNAME) must NOT have GRANT OPTION or DDL privileges.
         *
         * Required env vars (independent from the runtime connection):
         *   PROVISIONING_DB_URL      (optional; no runtime fallback)
         *   PROVISIONING_DB_HOST     (defaults to 127.0.0.1)
         *   PROVISIONING_DB_PORT     (defaults to 3306)
         *   PROVISIONING_DB_DATABASE (NO default — must be set explicitly)
         *   PROVISIONING_DB_USERNAME (NO default — must be set explicitly)
         *   PROVISIONING_DB_PASSWORD (NO default — must be set explicitly)
         */
        'mysql_provisioning' => [
            'driver'    => 'mysql',
            'url'       => env('PROVISIONING_DB_URL'),
            'host'      => env('PROVISIONING_DB_HOST', '127.0.0.1'),
            'port'      => env('PROVISIONING_DB_PORT', '3306'),
            'database'  => env('PROVISIONING_DB_DATABASE'),
            'username'  => env('PROVISIONING_DB_USERNAME'),
            'password'  => env('PROVISIONING_DB_PASSWORD'),
            'unix_socket' => env('PROVISIONING_DB_SOCKET', ''),
            'charset'   => env('PROVISIONING_DB_CHARSET', 'utf8mb4'),
            'collation' => env('PROVISIONING_DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix'    => '',
            'prefix_indexes' => true,
            'strict'    => true,
            'engine'    => null,
            'options'   => extension_loaded('pdo_mysql') ? array_filter([
                BINAA2_PDO_MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                BINAA2_PDO_MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];
