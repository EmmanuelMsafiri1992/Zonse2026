<?php

namespace App\Http\Controllers;

use App\Registries\SearchRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request, SearchRegistry $search): View
    {
        $q = trim((string) $request->query('q', ''));

        return view('search.index', [
            'q' => $q,
            'groups' => $search->search($q),
        ]);
    }
}
