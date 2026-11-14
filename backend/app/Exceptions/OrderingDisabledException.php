<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderingDisabledException extends Exception
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Toko ini tidak mengaktifkan fitur pemesanan.',
            'error_code' => 'ORDERING_DISABLED',
        ], 409);
    }
}
