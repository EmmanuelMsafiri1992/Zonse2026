<section class="z-hero">
    <div class="z-hero-glow" aria-hidden="true"></div>
    <div class="container position-relative">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 text-center text-lg-start">
                <span class="z-hero-chip mb-4"><span class="z-hero-pulse"></span> {{ $moduleCount }}+ apps · {{ $suites->count() }} suites · one login</span>
                <h1 class="z-hero-title mb-4">Every business system your profession needs, <span class="z-hero-accent">in one subscription.</span></h1>
                <p class="z-hero-lead mb-4">Accounting, invoicing, appointments, patients, students, stock, POS, HR, projects, tickets, tenants, farms, churches and more. Switch on what fits you. Nothing you don't need.</p>
                <div class="d-flex justify-content-center justify-content-lg-start gap-2 flex-wrap mb-4">
                    <a href="{{ route('register') }}" class="btn btn-lg z-hero-cta">Start free, no card needed <x-icon name="arrow-right" class="zi zi-sm" /></a>
                    <a href="{{ route('pricing') }}" class="btn btn-lg z-hero-ghost">See pricing</a>
                </div>
                <div class="z-hero-proof justify-content-center justify-content-lg-start">
                    <span><x-icon name="shield-check" class="zi zi-sm" /> Bank-grade security</span>
                    <span><x-icon name="smartphone" class="zi zi-sm" /> Works on any phone</span>
                    <span><x-icon name="wifi-off" class="zi zi-sm" /> USSD &amp; SMS ready</span>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="z-hero-stage" aria-hidden="true">
                    <svg class="z-hero-orbit" viewBox="0 0 600 600">
                        <defs>
                            <linearGradient id="zOrbit" x1="0" y1="0" x2="1" y2="1">
                                <stop offset="0" stop-color="#2BC4D4" stop-opacity=".55" />
                                <stop offset="1" stop-color="#A6D93B" stop-opacity=".15" />
                            </linearGradient>
                        </defs>
                        <circle cx="300" cy="300" r="285" fill="none" stroke="url(#zOrbit)" stroke-width="1.5" stroke-dasharray="3 9" />
                        <circle cx="300" cy="300" r="215" fill="none" stroke="url(#zOrbit)" stroke-width="1" />
                    </svg>

                    @foreach($suites->take(8) as $suite)
                        @php($angle = deg2rad(-90 + $loop->index * 45))
                        <span class="z-hero-planet" style="left: {{ round(50 + 47.5 * cos($angle), 2) }}%; top: {{ round(50 + 47.5 * sin($angle), 2) }}%; --d: {{ $loop->index * -0.7 }}s"><x-icon :name="$suite->icon ?: 'box'" class="zi" /></span>
                    @endforeach

                    <div class="z-hero-window">
                        <div class="z-hero-window-bar"><i></i><i></i><i></i><span>zonseob.com/dashboard</span></div>
                        <div class="z-hero-window-body">
                            <div class="z-hero-side">
                                <img src="{{ asset('images/logo-mark.png') }}" alt="" width="26" height="26">
                                @foreach(['layout-dashboard', 'receipt', 'calendar-days', 'package', 'users'] as $icon)
                                    <span class="{{ $loop->first ? 'is-active' : '' }}"><x-icon :name="$icon" class="zi zi-sm" /></span>
                                @endforeach
                            </div>
                            <div class="z-hero-main">
                                <div class="z-hero-kpis">
                                    <div><small>Revenue</small><b>2.4M</b><em class="up">+18%</em></div>
                                    <div><small>Invoices</small><b>312</b><em class="up">+9%</em></div>
                                    <div><small>Bookings</small><b>87</b><em>today</em></div>
                                </div>
                                <svg class="z-hero-chart" viewBox="0 0 320 120" preserveAspectRatio="none">
                                    <defs>
                                        <linearGradient id="zArea" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="0" stop-color="#2BC4D4" stop-opacity=".45" />
                                            <stop offset="1" stop-color="#2BC4D4" stop-opacity="0" />
                                        </linearGradient>
                                        <linearGradient id="zLine" x1="0" y1="0" x2="1" y2="0">
                                            <stop offset="0" stop-color="#2BC4D4" />
                                            <stop offset="1" stop-color="#A6D93B" />
                                        </linearGradient>
                                    </defs>
                                    <g stroke="rgba(255,255,255,.07)">
                                        <line x1="0" y1="30" x2="320" y2="30" /><line x1="0" y1="60" x2="320" y2="60" /><line x1="0" y1="90" x2="320" y2="90" />
                                    </g>
                                    <path d="M0 96 C30 90 45 70 75 74 S120 92 150 62 S200 40 230 48 S280 22 320 14 L320 120 L0 120 Z" fill="url(#zArea)" />
                                    <path class="z-hero-line" d="M0 96 C30 90 45 70 75 74 S120 92 150 62 S200 40 230 48 S280 22 320 14" fill="none" stroke="url(#zLine)" stroke-width="3" stroke-linecap="round" />
                                    <circle cx="320" cy="14" r="5" fill="#A6D93B" />
                                </svg>
                                <div class="z-hero-rows">
                                    <div><span class="dot" style="background:#A6D93B"></span>Mwale Pharmacy<em class="ok">Paid</em></div>
                                    <div><span class="dot" style="background:#2BC4D4"></span>Grace Academy<em class="info">Sent</em></div>
                                    <div><span class="dot" style="background:#FDAB3D"></span>Kamuzu Farms<em class="warn">Due</em></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="z-hero-float z-hero-float-1">
                        <span class="ico" style="background:#7AAE1A"><x-icon name="check" class="zi zi-sm" /></span>
                        <div><b>Invoice paid</b><small>MWK 450,000 · just now</small></div>
                    </div>
                    <div class="z-hero-float z-hero-float-2">
                        <span class="ico" style="background:#007C8A"><x-icon name="calendar-check" class="zi zi-sm" /></span>
                        <div><b>10:30 appointment</b><small>Patient checked in</small></div>
                    </div>
                    <div class="z-hero-float z-hero-float-3">
                        <span class="ico" style="background:#FDAB3D"><x-icon name="package" class="zi zi-sm" /></span>
                        <div><b>Low stock</b><small>Cement · 12 bags left</small></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
