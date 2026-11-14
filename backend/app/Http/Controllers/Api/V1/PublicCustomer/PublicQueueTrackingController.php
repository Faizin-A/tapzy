<?php

namespace App\Http\Controllers\Api\V1\PublicCustomer;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicCustomer\PublicQueueTrackingResource;
use App\Services\Customer\PublicQueueTrackingService;

class PublicQueueTrackingController extends Controller
{
    public function __construct(
        private readonly PublicQueueTrackingService $trackingService,
    ) {}

    /**
     * Return a read-only tracking snapshot for the queue identified by the
     * given access token. Returns HTTP 404 when the token is invalid, unknown,
     * or the owning store has been soft-deleted.
     */
    public function show(string $token): PublicQueueTrackingResource
    {
        $tracked = $this->trackingService->track($token);

        if ($tracked === null) {
            abort(404);
        }

        return PublicQueueTrackingResource::make($tracked);
    }
}
