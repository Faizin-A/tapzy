<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\LoginEligibility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDashboardAccountIsEligible
{
    public function __construct(private readonly LoginEligibility $eligibility) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->eligibility->allows($user)) {
            abort(403, 'Akun atau akses toko tidak tersedia.');
        }

        return $next($request);
    }
}
