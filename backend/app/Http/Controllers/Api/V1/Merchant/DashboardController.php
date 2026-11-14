<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\UpdateProfileRequest;
use App\Http\Resources\MerchantDashboardResource;
use App\Http\Resources\MerchantProfileResource;
use App\Models\Store;
use App\Models\User;
use App\Services\Merchant\MerchantDashboardService;
use App\Services\Store\StoreManagementService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly MerchantDashboardService $dashboardService) {}

    public function dashboard(Request $request): MerchantDashboardResource
    {
        return MerchantDashboardResource::make($this->dashboardService->summary($this->store($request)));
    }

    public function profile(Request $request): MerchantProfileResource
    {
        return MerchantProfileResource::make($this->store($request));
    }

    public function updateProfile(UpdateProfileRequest $request, StoreManagementService $storeService): MerchantProfileResource
    {
        $store = $storeService->update(
            $this->store($request),
            $request->validated(),
            $this->actor($request),
            $request->ip(),
            $request->userAgent(),
        );

        return MerchantProfileResource::make($store);
    }

    private function store(Request $request): Store
    {
        $store = $request->attributes->get('active_merchant_store');
        abort_unless($store instanceof Store, 403, 'Akses toko tidak tersedia.');

        return $store;
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403, 'Akses tidak tersedia.');

        return $user;
    }
}
