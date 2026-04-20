<?php

namespace App\Modules\Seguridad\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;

class PermisoPageController extends Controller
{
    public function index()
    {
        $token = session('auth_token');
        
        $response = Http::withToken($token)->get(config('app.api_url') . '/v1/seguridad/permisos');
        $data = $response->successful() ? $response->json()['data'] ?? [] : [];
        
        return view('seguridad.permisos.index', compact('data'));
    }
}