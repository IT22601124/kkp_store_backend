<?php

namespace App\Traits;

trait ApiResponse
{
    public function successResponse(
        $data = null,
        string $message = 'Success',
        int $statusCode = 200
    ) {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }

    public function errorResponse(
        string $message = 'Something went wrong',
        int $statusCode = 400,
        $errors = null
    ) {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $statusCode);
    }
}