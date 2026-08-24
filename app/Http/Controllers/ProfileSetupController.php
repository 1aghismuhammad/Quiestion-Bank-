<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Profile\CompleteUserProfile;
use App\Http\Requests\StoreProfileSetupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ProfileSetupController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if (request()->user()?->hasCompletedProfile()) {
            return to_route('dashboard');
        }

        return view('profile.setup');
    }

    public function store(
        StoreProfileSetupRequest $request,
        CompleteUserProfile $completeUserProfile,
    ): RedirectResponse {
        abort_if(
            $request->user()->hasCompletedProfile(),
            Response::HTTP_CONFLICT,
            'Profil sudah dilengkapi.',
        );

        $completeUserProfile->handle(
            $request->user(),
            $request->validated('phone_number'),
        );

        return to_route('dashboard')
            ->with('success', 'Profil berhasil dilengkapi.');
    }
}
