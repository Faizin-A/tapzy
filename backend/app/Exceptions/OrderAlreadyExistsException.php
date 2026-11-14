<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderAlreadyExistsException extends Exception
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Antrean ini sudah memiliki pesanan.',
            'error_code' => 'ORDER_ALREADY_EXISTS',
        ], 409);
    }
}
