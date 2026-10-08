<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    use AuthorizesRequests;

    /** Page size from ?per_page=, 25 by default and never more than 100. */
    protected function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->integer('per_page', 25)));
    }
}
