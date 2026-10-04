<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Send each profile to its start page: the dashboard for whoever can see
     * the indicators, the quick search for the atendente.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        return $request->user()->can('dashboard.view')
            ? redirect()->route('dashboard')
            : redirect()->route('quick-search');
    }
}
