<?php

declare(strict_types=1);

namespace Tests\Unit\Materials\Profile;

use App\Enums\MaterialProfileAttemptErrorCode;
use App\Enums\MaterialProfileAttemptStatus;
use App\Enums\MaterialProfileElementKind;
use App\Enums\MaterialProfileElementOrigin;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepPurpose;
use App\Enums\MaterialProfileStepStatus;
use Tests\TestCase;

class MaterialProfileEnumTest extends TestCase
{
    public function test_locked_enum_values(): void
    {
        $this->assertSame(['queued', 'processing', 'ready', 'failed'], array_column(MaterialProfileStatus::cases(), 'value'));
        $this->assertSame(['map', 'reduce'], array_column(MaterialProfileStepPurpose::cases(), 'value'));
        $this->assertSame(['queued', 'processing', 'ready', 'failed'], array_column(MaterialProfileStepStatus::cases(), 'value'));
        $this->assertSame(['started', 'succeeded', 'failed'], array_column(MaterialProfileAttemptStatus::cases(), 'value'));
        $this->assertSame(['extracted', 'suggested'], array_column(MaterialProfileElementOrigin::cases(), 'value'));
        $this->assertSame(['topic', 'objective', 'indicator', 'other'], array_column(MaterialProfileElementKind::cases(), 'value'));
        $this->assertSame(
            ['provider_timeout', 'provider_http', 'schema_invalid', 'validation_failed'],
            array_column(MaterialProfileAttemptErrorCode::cases(), 'value'),
        );
        $this->assertContains('in_flight_exists', array_column(MaterialProfileErrorCode::cases(), 'value'));
        $this->assertContains('stale_recovery', array_column(MaterialProfileErrorCode::cases(), 'value'));
        $this->assertContains('queued_abandoned', array_column(MaterialProfileErrorCode::cases(), 'value'));
        $this->assertSame(
            [
                'in_flight_exists',
                'material_ineligible',
                'material_empty',
                'material_too_large',
                'stale_recovery',
                'queued_abandoned',
                'step_aborted',
                'hash_mismatch',
                'not_next_step',
                'revoked',
                'duplicate_worker',
                'validation_failed',
            ],
            array_column(MaterialProfileErrorCode::cases(), 'value'),
        );
    }
}
