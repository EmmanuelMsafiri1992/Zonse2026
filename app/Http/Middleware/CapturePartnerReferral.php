<?php

namespace App\Http\Middleware;

use App\Support\Partners;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers a partner's referral code from any link carrying ?partner=CODE, so the
 * workspace the visitor goes on to create is linked to that partner.
 */
class CapturePartnerReferral
{
    public function __construct(protected Partners $partners) {}

    public function handle(Request $request, Closure $next): Response
    {
        $code = $request->query('partner');
        if ($request->isMethod('GET') && is_string($code) && $request->hasSession() && $this->partners->findByCode($code)) {
            $request->session()->put('partner_code', strtoupper($code));
        }

        return $next($request);
    }
}
