<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * The public marketing page.
 *
 * A controller rather than a route closure because closures cannot be serialised
 * by route:cache, and this is the one route every deploy hits first.
 */
class LandingController extends Controller
{
    public function __invoke(Request $request)
    {
        if ($request->user()) {
            return redirect()->to(Filament::getPanel('admin')->getHomeUrl() ?? '/admin');
        }

        return view('landing', [
            /*
             * Read from the plans table rather than restated in the template, so
             * the advertised limits and the limits Plan::allows() actually
             * enforces can never drift apart.
             */
            'plans' => Plan::orderBy('sort_order')->get(),
        ]);
    }
}
