<?php

namespace App\Modules\System\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Support\ApiResponse;

class HealthController extends Controller
{
    public function __invoke()
    {
        return ApiResponse::success(
            data: [
                'service' => config('app.name'),
                'timestamp' => now()->toIso8601String(),
            ],
            message: 'API available',
            code: 'HEALTH_OK',
        );
    }
}
