<?php

declare(strict_types=1);

namespace App\Foundation;

use Illuminate\Database\MySqlConnection;
use PDO;

class ProvisioningMySqlConnection extends MySqlConnection
{
    public function __construct(
        private readonly ProvisioningConnectionAccess $access,
        $pdo,
        $database = '',
        $tablePrefix = '',
        array $config = [],
    ) {
        parent::__construct($pdo, $database, $tablePrefix, $config);
    }

    public function getPdo()
    {
        $this->access->assertAuthorized();

        return parent::getPdo();
    }

    public function getReadPdo()
    {
        $this->access->assertAuthorized();

        return parent::getReadPdo();
    }

    public function getRawPdo(): PDO|\Closure|null
    {
        $this->access->assertAuthorized();

        return parent::getRawPdo();
    }

    public function getRawReadPdo(): PDO|\Closure|null
    {
        $this->access->assertAuthorized();

        return parent::getRawReadPdo();
    }
}
