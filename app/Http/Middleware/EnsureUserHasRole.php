<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        abort_unless(
            $request->user()?->hasRole(strtoupper($role)),
            Response::HTTP_FORBIDDEN,
        );

        return $next($request);
    }
}
