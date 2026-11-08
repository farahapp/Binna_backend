<?php

declare(strict_types=1);

namespace Tests\Unit\Foundation;

use App\Foundation\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantClassificationTest extends TestCase
{
    #[Test]
    public function it_classifies_only_the_three_approved_tenant_types(): void
    {
        $cases = [
            ['organization', 'contractor', 'contracting_company', null],
            ['individual', 'contractor', 'individual_contractor', null],
            ['organization', 'consultant', 'engineering_consultancy', null],
            ['organization', 'both', 'needs_review', 'unsupported_combination'],
            ['organization', null, 'needs_review', 'missing_activity_type'],
            ['individual', 'consultant', 'needs_review', 'unsupported_combination'],
            [null, 'contractor', 'needs_review', 'missing_legal_type'],
            ['owner', 'contractor', 'needs_review', 'unsupported_legal_type'],
            ['organization', 'owner', 'needs_review', 'unsupported_activity_type'],
        ];

        foreach ($cases as [$legalType, $activityType, $classification, $reason]) {
            $this->assertSame(
                [
                    'classification' => $classification,
                    'classification_reason' => $reason,
                ],
                Tenant::classificationFor($legalType, $activityType)
            );
        }
    }

    #[Test]
    public function legacy_tenant_without_evidence_of_legal_type_requires_review(): void
    {
        $this->assertSame(
            [
                'classification' => Tenant::CLASSIFICATION_NEEDS_REVIEW,
                'classification_reason' => 'missing_legal_type',
            ],
            Tenant::classificationFor(null, 'contractor'),
        );
    }
}
