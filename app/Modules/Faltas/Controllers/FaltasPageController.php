<?php

namespace App\Modules\Faltas\Controllers;

use App\Http\Controllers\WebPageController;
use App\Modules\Faltas\Services\FaltasService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FaltasPageController extends WebPageController
{
    public function __construct(private readonly FaltasService $service)
    {
    }

    public function index(): View
    {
        $user = $this->getUser();
        $data = $this->service->listForUser($user, request()->all());
        
        return view('faltas.index', compact('data'));
    }

    public function show(string $id): View
    {
        $user = $this->getUser();
        $item = $this->service->findForUser($user, $id);
        
        return view('faltas.show', compact('item'));
    }

    public function corregir(string $id): View
    {
        $user = $this->getUser();
        $item = $this->service->findForUser($user, $id);
        
        return view('faltas.corregir', compact('item'));
    }

    public function corregirPost(Request $request, string $id)
    {
        $user = $this->getUser();
        $result = $this->service->corregir($user, $id, $request->all());
        
        if ($result['success']) {
            return redirect()->route('faltas.show', $id)->with('success', $result['message']);
        }
        
        return back()->with('error', $result['message']);
    }

    public function anular(string $id)
    {
        $user = $this->getUser();
        $result = $this->service->anular($user, $id);
        
        if ($result['success']) {
            return redirect()->route('faltas.index')->with('success', $result['message']);
        }
        
        return back()->with('error', $result['message']);
    }
}