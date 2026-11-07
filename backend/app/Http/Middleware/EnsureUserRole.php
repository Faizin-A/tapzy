<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->role !== $role) {
            abort(403, 'Forbidden.');
        }

        if (($role === 'super_admin' && $user->store_id !== null)
            || ($role === 'merchant' && $user->store_id === null)) {
            abort(403, 'Forbidden.');
        }

        return $next($request);
    }
}
