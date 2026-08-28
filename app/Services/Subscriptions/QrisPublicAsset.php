<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use Illuminate\Support\Facades\Storage;

class QrisPublicAsset
{
    public function path(): string
    {
        return (string) config('subscriptions.qris_path');
    }

    public function exists(): bool
    {
        $path = $this->path();

        return $path !== '' && Storage::disk('public')->exists($path);
    }

    public function url(): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        return Storage::disk('public')->url($this->path());
    }
}
