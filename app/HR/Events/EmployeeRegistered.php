<?php
declare(strict_types=1);
namespace App\HR\Events;
use App\HR\Employee;
class EmployeeRegistered
{
    public function __construct(
        public readonly Employee $employee,
        public readonly string $tenantSlug,
    ) {}
}
