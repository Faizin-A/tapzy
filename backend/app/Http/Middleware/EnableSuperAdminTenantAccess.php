<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnableSuperAdminTenantAccess
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403, 'Akses lintas tenant tidak tersedia.');
        }

        $this->tenantContext->enablePlatformAccess($user);

        try {
            return $next($request);
        } finally {
            $this->tenantContext->clear();
        }
    }
}
