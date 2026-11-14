<?php

namespace App\Http\Controllers\Api\V1\PublicCustomer;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicCustomer\CreateCustomerOrderRequest;
use App\Http\Resources\PublicCustomer\PublicOrderResource;
use App\Services\Customer\CustomerOrderCreationService;
use Illuminate\Http\JsonResponse;

class PublicOrderController extends Controller
{
    public function __construct(
        private readonly CustomerOrderCreationService $orderCreationService,
    ) {}

    /**
     * Create an order for the queue identified by the customer access token.
     * The access token is a bearer capability; no merchant tenant context is
     * accepted from the request.
     */
    public function store(
        string $token,
        CreateCustomerOrderRequest $request
    ): JsonResponse {
        $order = $this->orderCreationService->create($token, $request->validated());

        if ($order === null) {
            abort(404);
        }

        /** @var JsonResponse $response */
        $response = PublicOrderResource::make($order)
            ->response()
            ->setStatusCode(201);

        return $response;
    }
}
