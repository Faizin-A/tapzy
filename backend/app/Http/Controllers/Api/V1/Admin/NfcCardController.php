<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NfcCard\IndexNfcCardRequest;
use App\Http\Requests\Admin\NfcCard\StoreNfcCardRequest;
use App\Http\Requests\Admin\NfcCard\UpdateNfcCardRequest;
use App\Http\Requests\Admin\NfcCard\UpdateNfcCardStatusRequest;
use App\Http\Resources\NfcCardCreatedResource;
use App\Http\Resources\NfcCardResource;
use App\Models\NfcCard;
use App\Models\Store;
use App\Models\User;
use App\Services\NfcCard\NfcCardManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class NfcCardController extends Controller
{
    public function __construct(
        private readonly NfcCardManagementService $nfcCardManagementService,
    ) {}

    public function index(IndexNfcCardRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $query = NfcCard::query();

        if (($validated['store_id'] ?? null) !== null) {
            $query->where('store_id', (int) $validated['store_id']);
        }

        $search = $validated['search'] ?? null;
        if ($search !== null && $search !== '') {
            $query->where(function ($query) use ($search): void {
                $query->where('label', 'like', "%{$search}%")
                    ->orWhere('card_uid', 'like', "%{$search}%");
            });
        }

        if (($validated['type'] ?? null) !== null) {
            $query->where('type', $validated['type']);
        }

        if (array_key_exists('is_active', $validated) && $validated['is_active'] !== null) {
            $query->where('is_active', (bool) $validated['is_active']);
        }

        $cards = $query
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 15))
            ->withQueryString();

        return NfcCardResource::collection($cards);
    }

    public function store(StoreNfcCardRequest $request, Store $store): JsonResponse
    {
        $result = $this->nfcCardManagementService->create(
            $store,
            $request->validated(),
            $this->actor($request),
            $request->ip(),
            $request->userAgent(),
        );

        return NfcCardCreatedResource::make(
            $result['card'],
            $result['public_token'],
            $result['qr_code'] ?? null,
            $result['public_url'] ?? null,
        )
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, NfcCard $nfcCard): NfcCardResource
    {
        Gate::forUser($this->actor($request))->authorize('view', $nfcCard);

        return NfcCardResource::make($nfcCard);
    }

    public function update(UpdateNfcCardRequest $request, NfcCard $nfcCard): NfcCardResource
    {
        $card = $this->nfcCardManagementService->update(
            $nfcCard,
            $request->validated(),
            $this->actor($request),
            $request->ip(),
            $request->userAgent(),
        );

        return NfcCardResource::make($card);
    }

    public function updateStatus(UpdateNfcCardStatusRequest $request, NfcCard $nfcCard): NfcCardResource
    {
        $card = $this->nfcCardManagementService->setActive(
            $nfcCard,
            (bool) $request->validated('is_active'),
            $this->actor($request),
            $request->ip(),
            $request->userAgent(),
        );

        return NfcCardResource::make($card);
    }

    public function destroy(Request $request, NfcCard $nfcCard): Response
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('delete', $nfcCard);

        $this->nfcCardManagementService->delete(
            $nfcCard,
            $actor,
            $request->ip(),
            $request->userAgent(),
        );

        return response()->noContent();
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403, 'Akses tidak tersedia.');
        }

        return $user;
    }
}
