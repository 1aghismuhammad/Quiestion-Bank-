<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RoleName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        if ($request->user()->hasRole(RoleName::ADMIN)) {
            return to_route('admin.dashboard');
        }

        return view('dashboard', [
            'user' => $request->user(),
        ]);
    }
}
