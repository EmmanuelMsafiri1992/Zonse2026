<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\Plan;
use App\Models\Profession;
use App\Models\Suite;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        if ($request->user()) {
            return redirect()->route('dashboard');
        }

        return view('landing.index', [
            'suites' => Suite::withCount('modules')->orderBy('sort_order')->get(),
            'moduleCount' => Module::count(),
            'professions' => Profession::where('is_featured', true)->orderBy('sort_order')->limit(12)->get(),
            'plans' => Plan::active()->get(),
        ]);
    }

    public function pricing()
    {
        return view('landing.pricing', ['plans' => Plan::active()->with('modules')->get()]);
    }
}
