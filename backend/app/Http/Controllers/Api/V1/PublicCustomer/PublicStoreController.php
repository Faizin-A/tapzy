<?php

namespace App\Http\Controllers\Api\V1\PublicCustomer;

use App\Exceptions\OrderingDisabledException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PublicCustomer\CreateCustomerQueueRequest;
use App\Http\Resources\PublicCustomer\PublicProductCatalogResource;
use App\Http\Resources\PublicCustomer\PublicQueueCreatedResource;
use App\Http\Resources\PublicCustomer\PublicQueueStatusResource;
use App\Http\Resources\PublicCustomer\PublicStoreResource;
use App\Services\Customer\CustomerQueueCreationService;
use App\Services\Customer\PublicProductCatalogService;
use App\Services\Customer\PublicStoreQueueService;
use App\Services\Customer\PublicStoreResolutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicStoreController extends Controller
{
    public function __construct(
        private readonly PublicStoreResolutionService $resolver,
        private readonly PublicStoreQueueService $queueService,
        private readonly PublicProductCatalogService $catalogService,
        private readonly CustomerQueueCreationService $queueCreationService,
    ) {}

    /**
     * Return the public profile for the store identified by the given NFC/QR
     * token. Returns HTTP 404 if the token is invalid or the store/card is not
     * operational. Never updates last_used_at.
     */
    public function show(string $token): PublicStoreResource
    {
        $resolved = $this->resolver->resolve($token);

        if ($resolved === null) {
            abort(404);
        }

        return PublicStoreResource::make($resolved['store']);
    }

    /**
     * Return a live queue-status snapshot for the store identified by the
     * given NFC/QR token. Returns HTTP 404 if the token is invalid or the
     * store/card is not operational. Never updates last_used_at.
     */
    public function queueStatus(string $token): PublicQueueStatusResource
    {
        $resolved = $this->resolver->resolve($token);

        if ($resolved === null) {
            abort(404);
        }

        $status = $this->queueService->getStatus($resolved['store']);

        return PublicQueueStatusResource::make($status);
    }

    /**
     * Return the public product catalog for the store identified by the given
     * NFC/QR token. Accepts optional ?category and ?search query parameters.
     * Returns HTTP 200 with empty arrays when no eligible products exist.
     * Returns HTTP 404 when the token is invalid or the store is not operational.
     */
    public function products(string $token, Request $request): PublicProductCatalogResource
    {
        $validated = $request->validate([
            'category' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $resolved = $this->resolver->resolve($token);

        if ($resolved === null) {
            abort(404);
        }

        if ($resolved['store']->ordering_mode === 'queue_only') {
            throw new OrderingDisabledException;
        }

        $category = isset($validated['category']) && is_string($validated['category']) && $validated['category'] !== ''
            ? $validated['category']
            : null;
        $search = isset($validated['search']) && is_string($validated['search']) && $validated['search'] !== ''
            ? $validated['search']
            : null;

        // Treat empty strings as null so the service can skip the filter cleanly.
        $catalog = $this->catalogService->getCatalog(
            $resolved['store'],
            $category,
            $search,
        );

        return PublicProductCatalogResource::make($catalog);
    }

    /**
     * Create a new queue ticket for the customer via NFC/QR token.
     * Enforces Idempotency-Key header, pessimistic locking on the active
     * queue session, gapless queue numbering, and CSPRNG access token generation.
     * Replaying with the same key returns the existing ticket with HTTP 200 and
     * The access token is returned for both the initial request and a valid replay.
     */
    public function storeQueue(
        string $token,
        CreateCustomerQueueRequest $request
    ): JsonResponse {
        $resolved = $this->resolver->resolve($token);

        if ($resolved === null) {
            abort(404);
        }

        $idempotencyKey = $request->getIdempotencyKey();

        $result = $this->queueCreationService->create(
            store: $resolved['store'],
            card: $resolved['card'],
            validatedData: $request->validated(),
            idempotencyKey: $idempotencyKey
        );

        $isReplay = (bool) ($result['is_replay'] ?? false);
        $statusCode = $isReplay ? 200 : 201;

        /** @var JsonResponse $response */
        $response = PublicQueueCreatedResource::make($result)
            ->response()
            ->setStatusCode($statusCode);

        if ($isReplay) {
            $response->header('X-Idempotent-Replay', 'true');
        }

        return $response;
    }
}
