<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'totalUsers' => User::query()->count(),
            'totalAdmins' => User::query()
                ->whereHas('roles', fn ($query) => $query->where('role_name', RoleName::ADMIN->value))
                ->count(),
        ]);
    }
}
