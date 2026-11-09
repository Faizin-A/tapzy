<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Store\CreateStoreRequest;
use App\Http\Requests\Admin\Store\ExpiryAlertsRequest;
use App\Http\Requests\Admin\Store\IndexStoreRequest;
use App\Http\Requests\Admin\Store\UpdateStoreExpiryRequest;
use App\Http\Requests\Admin\Store\UpdateStoreRequest;
use App\Http\Requests\Admin\Store\UpdateStoreStatusRequest;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Models\User;
use App\Services\Store\StoreManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

class StoreController extends Controller
{
    public function __construct(
        private readonly StoreManagementService $storeManagementService,
    ) {}

    public function index(IndexStoreRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $query = Store::query();

        $search = $validated['search'] ?? null;
        if ($search !== null && $search !== '') {
            $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if (array_key_exists('is_active', $validated) && $validated['is_active'] !== null) {
            $query->where('is_active', (bool) $validated['is_active']);
        }

        $days = (int) ($validated['days'] ?? 7);
        switch ($validated['expiry_status'] ?? null) {
            case 'expired':
                $query->expired();
                break;
            case 'expiring':
                $query->expiringWithin($days);
                break;
            case 'valid':
                $query->notExpired();
                break;
        }

        $stores = $query
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 15))
            ->withQueryString();

        return StoreResource::collection($stores);
    }

    public function store(CreateStoreRequest $request): JsonResponse
    {
        $store = $this->storeManagementService->create(
            $request->validated(),
            $this->actor($request),
            $request->ip(),
            $request->userAgent(),
        );

        return StoreResource::make($store)->response()->setStatusCode(201);
    }

    public function show(Request $request, Store $store): StoreResource
    {
        Gate::forUser($this->actor($request))->authorize('manage', $store);

        return StoreResource::make($store);
    }

    public function update(UpdateStoreRequest $request, Store $store): StoreResource
    {
        $store = $this->storeManagementService->update(
            $store,
            $request->validated(),
            $this->actor($request),
            $request->ip(),
            $request->userAgent(),
        );

        return StoreResource::make($store);
    }

    public function updateStatus(UpdateStoreStatusRequest $request, Store $store): StoreResource
    {
        $store = $this->storeManagementService->setActive(
            $store,
            (bool) $request->validated('is_active'),
            $this->actor($request),
            $request->ip(),
            $request->userAgent(),
        );

        return StoreResource::make($store);
    }

    public function updateExpiry(UpdateStoreExpiryRequest $request, Store $store): StoreResource
    {
        $store = $this->storeManagementService->setExpiry(
            $store,
            (string) $request->validated('expires_at'),
            $this->actor($request),
            $request->ip(),
            $request->userAgent(),
        );

        return StoreResource::make($store);
    }

    public function destroy(Request $request, Store $store): Response
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('delete', $store);

        $this->storeManagementService->delete(
            $store,
            $actor,
            $request->ip(),
            $request->userAgent(),
        );

        return response()->noContent();
    }

    public function expiryAlerts(ExpiryAlertsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $days = (int) ($validated['days'] ?? 7);
        $perPage = (int) ($validated['per_page'] ?? 15);
        $expiredPage = (int) ($validated['expired_page'] ?? 1);
        $expiringPage = (int) ($validated['expiring_page'] ?? 1);

        $expired = Store::query()
            ->expired()
            ->orderBy('expires_at')
            ->paginate($perPage, ['*'], 'expired_page', $expiredPage);

        $expiring = Store::query()
            ->expiringWithin($days)
            ->orderBy('expires_at')
            ->paginate($perPage, ['*'], 'expiring_page', $expiringPage);

        return response()->json([
            'success' => true,
            'data' => [
                'expired' => [
                    'data' => StoreResource::collection($expired->items())->resolve(),
                    'meta' => $this->paginationMeta($expired),
                ],
                'expiring' => [
                    'data' => StoreResource::collection($expiring->items())->resolve(),
                    'meta' => $this->paginationMeta($expiring),
                ],
            ],
            'meta' => ['days' => $days],
        ]);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403, 'Akses tidak tersedia.');
        }

        return $user;
    }

    /**
     * @return array<string, int>
     */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
