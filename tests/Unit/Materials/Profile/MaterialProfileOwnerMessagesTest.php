<?php

declare(strict_types=1);

namespace Tests\Unit\Materials\Profile;

use App\Support\MaterialProfiles\MaterialProfileOwnerMessages;
use Tests\TestCase;

class MaterialProfileOwnerMessagesTest extends TestCase
{
    public function test_oversized_message_formats_the_configured_limit(): void
    {
        config(['material_profile.max_canonical_chars' => 240_000]);

        $this->assertSame(
            'Materi terlalu panjang untuk dianalisis. Batas maksimal 240.000 karakter.',
            MaterialProfileOwnerMessages::materialTooLarge(),
        );
    }

    public function test_oversized_message_does_not_hard_code_a_second_limit(): void
    {
        config(['material_profile.max_canonical_chars' => 50]);

        $this->assertSame(
            'Materi terlalu panjang untuk dianalisis. Batas maksimal 50 karakter.',
            MaterialProfileOwnerMessages::materialTooLarge(),
        );
    }
}
