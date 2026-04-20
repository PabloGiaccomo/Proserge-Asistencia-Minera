<?php

namespace App\Modules\System\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\Request;

class ScopeCheckController extends Controller
{
    public function __invoke(Request $request)
    {
        return ApiResponse::success(
            data: [
                'usuario_id' => $request->user()?->id,
                'mina_id' => $request->attributes->get('scope_mina_id'),
            ],
            message: 'Auth y scope validados',
            code: 'SCOPE_CHECK_OK',
        );
    }
}
