<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\Queue\IndexQueueRequest;
use App\Http\Requests\Merchant\Queue\CreateManualQueueRequest;
use App\Http\Requests\Merchant\Queue\QueueOperationRequest;
use App\Http\Resources\MerchantQueueResource;
use App\Models\Queue;
use App\Models\Store;
use App\Models\User;
use App\Services\Merchant\QueueManagementService;
use App\Services\Merchant\ManualQueueCreationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class QueueController extends Controller
{
    public function __construct(private readonly QueueManagementService $service) {}

    public function storeManual(CreateManualQueueRequest $request, ManualQueueCreationService $service): JsonResponse
    {
        $queue = $service->create($this->store($request), $request->validated(), $request->idempotencyKey());
        /** @var JsonResponse $response */
        $response = MerchantQueueResource::make($queue)->response()->setStatusCode(201);
        return $response;
    }

    public function index(IndexQueueRequest $request): AnonymousResourceCollection
    {
        return MerchantQueueResource::collection(
            $this->service->list($this->store($request), $request->validated())
        );
    }

    public function show(Request $request, Queue $queue): MerchantQueueResource
    {
        Gate::forUser($this->actor($request))->authorize('view', $queue);

        return MerchantQueueResource::make($this->service->find($this->store($request), $queue));
    }

    public function callNext(QueueOperationRequest $request): MerchantQueueResource
    {
        return MerchantQueueResource::make($this->service->callNext($this->store($request)));
    }

    public function call(QueueOperationRequest $request, Queue $queue): MerchantQueueResource
    {
        Gate::forUser($this->actor($request))->authorize('update', $queue);

        return MerchantQueueResource::make($this->service->call($this->store($request), $queue));
    }

    public function start(QueueOperationRequest $request, Queue $queue): MerchantQueueResource
    {
        Gate::forUser($this->actor($request))->authorize('update', $queue);

        return MerchantQueueResource::make($this->service->start($this->store($request), $queue));
    }

    public function skip(QueueOperationRequest $request, Queue $queue): MerchantQueueResource
    {
        Gate::forUser($this->actor($request))->authorize('update', $queue);

        return MerchantQueueResource::make($this->service->skip($this->store($request), $queue));
    }

    public function cancel(QueueOperationRequest $request, Queue $queue): MerchantQueueResource
    {
        Gate::forUser($this->actor($request))->authorize('update', $queue);

        return MerchantQueueResource::make($this->service->cancel($this->store($request), $queue));
    }

    public function complete(QueueOperationRequest $request, Queue $queue): MerchantQueueResource
    {
        Gate::forUser($this->actor($request))->authorize('update', $queue);

        return MerchantQueueResource::make($this->service->complete($this->store($request), $queue));
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
