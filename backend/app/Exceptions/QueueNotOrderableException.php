<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QueueNotOrderableException extends Exception
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Antrean ini tidak dapat dipesan pada status saat ini.',
            'error_code' => 'QUEUE_NOT_ORDERABLE',
        ], 409);
    }
}
