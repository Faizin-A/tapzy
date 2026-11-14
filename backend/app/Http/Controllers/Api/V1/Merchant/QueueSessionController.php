<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\QueueSession\QueueSessionActionRequest;
use App\Http\Resources\MerchantQueueSessionResource;
use App\Models\Store;
use App\Models\User;
use App\Services\Merchant\QueueSessionManagementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\JsonResponse;

class QueueSessionController extends Controller
{
    public function __construct(private readonly QueueSessionManagementService $service) {}

    public function show(Request $request): JsonResource|JsonResponse
    {
        $session = $this->service->current($this->store($request));

        if ($session === null) {
            return response()->json(['data' => null]);
        }

        return MerchantQueueSessionResource::make($session);
    }

    public function open(QueueSessionActionRequest $request): MerchantQueueSessionResource
    {
        return MerchantQueueSessionResource::make($this->service->open($this->store($request), $this->actor($request), $request->ip(), $request->userAgent()));
    }

    public function close(QueueSessionActionRequest $request): MerchantQueueSessionResource
    {
        return MerchantQueueSessionResource::make($this->service->close($this->store($request), $this->actor($request), $request->ip(), $request->userAgent()));
    }

    public function reset(QueueSessionActionRequest $request): MerchantQueueSessionResource
    {
        return MerchantQueueSessionResource::make($this->service->reset($this->store($request), $this->actor($request), $request->ip(), $request->userAgent()));
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
