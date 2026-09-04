<?php

declare(strict_types=1);

namespace Tests\Unit\Materials\Profile;

use App\Support\Materials\MaterialContentHasher;
use Tests\TestCase;

class MaterialContentHasherTest extends TestCase
{
    public function test_hash_is_stable_sha256_of_exact_utf8_bytes(): void
    {
        $hasher = new MaterialContentHasher;
        $content = "Pendidikan café 😀\n";

        $this->assertSame(hash('sha256', $content), $hasher->hash($content));
        $this->assertSame($hasher->hash($content), $hasher->hash($content));
        $this->assertNotSame($hasher->hash($content), $hasher->hash($content.' '));
    }
}
