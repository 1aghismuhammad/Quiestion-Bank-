<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\BuildAdminUserDetail;
use App\Actions\Admin\ListAdminUsers;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, ListAdminUsers $listAdminUsers): View
    {
        $this->authorize('viewAny', User::class);

        return view('admin.users.index', [
            'users' => $listAdminUsers->handle($request->query()),
            'filters' => [
                'q' => is_string($request->query('q')) ? $request->query('q') : '',
                'status' => is_string($request->query('status')) ? $request->query('status') : '',
                'role' => is_string($request->query('role')) ? $request->query('role') : '',
                'plan' => is_string($request->query('plan')) ? $request->query('plan') : '',
            ],
        ]);
    }

    public function show(User $user, BuildAdminUserDetail $buildAdminUserDetail): View
    {
        $this->authorize('view', $user);

        $user->load('roles');

        return view('admin.users.show', [
            'account' => $user,
            ...$buildAdminUserDetail->handle($user),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $user->update([
            'status' => UserStatus::from($request->validated('status')),
        ]);

        return to_route('admin.users.show', $user)
            ->with('success', 'Status akun diperbarui.');
    }
}
