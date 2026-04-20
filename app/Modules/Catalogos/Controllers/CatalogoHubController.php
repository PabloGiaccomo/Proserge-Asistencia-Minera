<?php

namespace App\Modules\Catalogos\Controllers;

use App\Http\Controllers\WebPageController;
use Illuminate\View\View;

class CatalogoHubController extends WebPageController
{
    public function index(): View
    {
        return view('catalogos.index');
    }
}
