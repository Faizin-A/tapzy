<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveMerchantStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->role !== 'merchant' || $user->store_id === null) {
            abort(403, 'Akses toko tidak tersedia.');
        }

        $store = Store::query()
            ->whereKey($user->store_id)
            ->where('is_active', true)
            ->notExpired()
            ->first();

        if ($store === null) {
            abort(403, 'Akses toko tidak tersedia.');
        }

        $request->attributes->set('active_merchant_store', $store);

        return $next($request);
    }
}
