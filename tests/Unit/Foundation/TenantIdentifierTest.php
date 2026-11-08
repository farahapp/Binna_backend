<?php

declare(strict_types=1);

namespace Tests\Unit\Foundation;

use App\Foundation\TenantIdentifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TenantIdentifierTest extends TestCase
{
    #[DataProvider('numbers')]
    public function test_it_normalizes_identifier_numbers(string $input, string $expected): void
    {
        self::assertSame($expected, TenantIdentifier::normalize($input));
    }

    public static function numbers(): array
    {
        return [
            [' CR-12 34 ', 'CR1234'],
            ['٢٨-١٠٠', '28100'],
            ['qid/123', 'QID123'],
        ];
    }
}
