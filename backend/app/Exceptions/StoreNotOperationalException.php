<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreNotOperationalException extends Exception
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Toko sedang tidak dapat menerima pesanan.',
            'error_code' => 'STORE_NOT_OPERATIONAL',
        ], 409);
    }
}
