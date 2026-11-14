<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QueueSessionInactiveException extends Exception
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Sesi antrean toko saat ini sedang tidak aktif atau belum dibuka.',
            'error_code' => 'QUEUE_SESSION_INACTIVE',
        ], 409);
    }
}
