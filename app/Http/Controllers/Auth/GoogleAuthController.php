<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AuthenticateGoogleUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(
        Request $request,
        AuthenticateGoogleUser $authenticateGoogleUser,
    ): RedirectResponse {
        try {
            $user = $authenticateGoogleUser->handle(
                Socialite::driver('google')->user(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return to_route('home')
                ->with('error', 'Login Google gagal. Silakan coba kembali.');
        }

        Auth::login($user);
        $request->session()->regenerate();

        if (! $user->hasCompletedProfile()) {
            return to_route('profile.setup');
        }

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('home');
    }
}
