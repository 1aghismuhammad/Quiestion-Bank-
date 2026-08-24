<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::query()->updateOrCreate(
            ['role_name' => RoleName::ADMIN->value],
            ['description' => 'Administrator aplikasi'],
        );

        Role::query()->updateOrCreate(
            ['role_name' => RoleName::USER->value],
            ['description' => 'Pengguna AI Question Bank'],
        );
    }
}
