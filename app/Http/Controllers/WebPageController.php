<?php

namespace App\Http\Controllers;

use App\Shared\Concerns\UsesAuthenticatedUser;

class WebPageController extends Controller
{
    use UsesAuthenticatedUser;

    protected function user(): ?\App\Models\Usuario
    {
        // Don't call DB - just use session data for views
        return null;
    }
}