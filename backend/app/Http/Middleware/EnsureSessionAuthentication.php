<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSessionAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() !== null || ! Auth::guard('web')->check()) {
            abort(401, 'Unauthenticated.');
        }

        $authenticatedUser = Auth::guard('web')->user();
        $sessionGeneration = $request->session()->get('auth_session_generation');

        if (! $authenticatedUser instanceof User
            || ! is_int($sessionGeneration)
            || $sessionGeneration < 1) {
            $this->invalidateSession($request);
        }

        $currentUser = User::query()->find($authenticatedUser->getKey());
        $currentGeneration = $currentUser?->session_generation;

        if (! $currentUser instanceof User
            || ! is_int($currentGeneration)
            || $currentGeneration < 1
            || $sessionGeneration !== $currentGeneration) {
            $this->invalidateSession($request);
        }

        return $next($request);
    }

    private function invalidateSession(Request $request): never
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::forgetGuards();

        abort(401, 'Unauthenticated.');
    }
}
