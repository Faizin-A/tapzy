<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $store = $request->attributes->get('active_merchant_store');

        if (! $user instanceof User || ! $store instanceof Store) {
            abort(403, 'Tenant context tidak tersedia.');
        }

        $this->tenantContext->setMerchantStore($user, $store);

        try {
            return $next($request);
        } finally {
            $this->tenantContext->clear();
        }
    }
}
