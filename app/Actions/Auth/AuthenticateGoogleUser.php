<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use UnexpectedValueException;

class AuthenticateGoogleUser
{
    public function handle(SocialiteUser $googleUser): User
    {
        $googleId = (string) $googleUser->getId();
        $email = $googleUser->getEmail();

        if ($googleId === '' || $email === null || $email === '') {
            throw new UnexpectedValueException('Google account must provide an ID and email address.');
        }

        return DB::transaction(function () use ($googleUser, $googleId, $email): User {
            $userByGoogleId = User::query()
                ->where('google_id', $googleId)
                ->lockForUpdate()
                ->first();
            $userByEmail = User::query()
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            if (
                $userByGoogleId !== null
                && $userByEmail !== null
                && ! $userByGoogleId->is($userByEmail)
            ) {
                throw new UnexpectedValueException(
                    'The Google account ID and email address belong to different users.',
                );
            }

            $user = $userByGoogleId ?? $userByEmail;
            $isNewUser = $user === null;

            if ($user !== null && $user->google_id !== null && $user->google_id !== $googleId) {
                throw new UnexpectedValueException('The email address is already linked to another Google account.');
            }

            if ($user !== null && $user->status !== UserStatus::ACTIVE) {
                throw new AuthorizationException('Your account is not active.');
            }

            $user ??= new User;

            $user->fill([
                'google_id' => $googleId,
                'name' => $googleUser->getName() ?: $googleUser->getNickname() ?: $email,
                'email' => $email,
                'avatar_url' => $googleUser->getAvatar(),
                'last_login_at' => now(),
            ]);

            if (! $user->exists) {
                $user->status = UserStatus::ACTIVE;
                $user->marketing_consent = false;
            }

            $user->save();

            if ($isNewUser) {
                $defaultRole = Role::query()->firstOrCreate(
                    ['role_name' => RoleName::USER->value],
                    ['description' => 'Pengguna AI Question Bank'],
                );

                $user->roles()->attach($defaultRole, ['created_at' => now()]);
            }

            return $user->load('roles', 'whatsappContact');
        });
    }
}
