<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\GenerationResetStrategy;
use App\Enums\PlanCode;
use App\Enums\PlanStatus;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::query()->updateOrCreate(
            ['code' => PlanCode::FREE->value],
            [
                'name' => 'Free',
                'storage_limit_bytes' => 52_428_800,
                'generation_limit' => 2,
                'generation_reset_strategy' => GenerationResetStrategy::LIFETIME->value,
                'status' => PlanStatus::ACTIVE->value,
            ],
        );

        Plan::query()->updateOrCreate(
            ['code' => PlanCode::PRO->value],
            [
                'name' => 'Pro',
                'storage_limit_bytes' => 524_288_000,
                'generation_limit' => 100,
                'generation_reset_strategy' => GenerationResetStrategy::MONTHLY->value,
                'status' => PlanStatus::ACTIVE->value,
            ],
        );
    }
}
