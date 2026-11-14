<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdempotencyRecoveryUnavailableException extends Exception
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Antrean sudah diproses, tetapi aksesnya tidak dapat dipulihkan. Silakan coba lagi dengan antrean baru.',
            'error_code' => 'IDEMPOTENCY_RECOVERY_UNAVAILABLE',
        ], 409);
    }
}
