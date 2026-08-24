<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProfileIsComplete
{
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        if (! $request->user()?->hasCompletedProfile()) {
            return to_route('profile.setup');
        }

        return $next($request);
    }
}
