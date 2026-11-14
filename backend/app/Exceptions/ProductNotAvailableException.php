<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductNotAvailableException extends Exception
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Salah satu produk tidak tersedia atau tidak dapat dipesan.',
            'error_code' => 'PRODUCT_NOT_AVAILABLE',
        ], 422);
    }
}
