<?php

declare(strict_types=1);

namespace Tests\Feature\Materials;

use Tests\TestCase;

class ZipExtensionRequiredTest extends TestCase
{
    public function test_zip_extension_is_loaded(): void
    {
        $this->assertTrue(extension_loaded('zip'));
    }
}
