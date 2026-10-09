<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Support for the in-page navigation in resources/js/navigate.js, which loads pages and submits
 * forms with fetch() and marks those requests with the X-Zonseo-Navigate header. fetch() cannot
 * follow a redirect to another site (a payment page, a sign-in provider), so such a redirect is
 * handed back as a header and the browser goes there itself.
 */
class HandleInstantNavigation
{
    public const HEADER = 'X-Zonseo-Navigate';

    public const LOCATION_HEADER = 'X-Zonseo-Location';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->headers->has(self::HEADER) && $response instanceof RedirectResponse && ! $this->isSameHost($request, $response->getTargetUrl())) {
            return response('', 204)->header(self::LOCATION_HEADER, $response->getTargetUrl());
        }

        return $response;
    }

    protected function isSameHost(Request $request, string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return ! $host || strcasecmp($host, $request->getHost()) === 0;
    }
}
