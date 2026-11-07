<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsNotSoftDeleted
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || User::withTrashed()
            ->whereKey($user->getKey())
            ->whereNotNull('deleted_at')
            ->exists()) {
            abort(401, 'Unauthenticated.');
        }

        return $next($request);
    }
}
