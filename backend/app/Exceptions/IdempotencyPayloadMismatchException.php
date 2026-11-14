<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdempotencyPayloadMismatchException extends Exception
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Idempotency-Key telah digunakan dengan parameter permintaan yang berbeda.',
            'error_code' => 'IDEMPOTENCY_PAYLOAD_MISMATCH',
        ], 409);
    }
}
