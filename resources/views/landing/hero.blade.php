<section class="z-hero">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <h1 class="z-hero-title mb-3">Run your whole business from one place.</h1>
                <p class="z-hero-lead mb-4">Invoices, bookings, patients, students, stock, staff, projects and more. {{ config('app.name') }} has an app for every part of your work, and you switch on only the ones you need.</p>
                <a href="{{ route('register') }}" class="btn btn-primary btn-lg px-4">Start free</a>
                <div class="z-hero-note">No card needed. Paid plans include a free trial. <a href="{{ route('pricing') }}">See pricing</a></div>
                <div class="z-hero-trust">
                    <span><x-icon name="check" class="zi zi-sm" /> {{ $moduleCount }} apps across {{ $suites->count() }} suites</span>
                    <span><x-icon name="check" class="zi zi-sm" /> Multi-branch and multi-currency</span>
                    <span><x-icon name="check" class="zi zi-sm" /> Pay in your local currency</span>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="z-shot" aria-hidden="true">
                    <div class="z-shot-bar"><i></i><i></i><i></i><span>zonseob.com/dashboard</span></div>
                    <div class="z-shot-body">
                        <div class="z-shot-side">
                            <div class="z-shot-brand"><img src="{{ asset('images/logo-mark.png') }}" alt=""> Zonseob</div>
                            <span class="is-active"><x-icon name="layout-dashboard" class="zi" /> Dashboard</span>
                            <span><x-icon name="inbox" class="zi" /> Inbox</span>
                            <span><x-icon name="bot" class="zi" /> Zonseob AI</span>
                            <div class="z-shot-group">Apps</div>
                            <span><x-icon name="users" class="zi" /> Contacts</span>
                            <span><x-icon name="receipt" class="zi" /> Invoices</span>
                            <span><x-icon name="calendar-days" class="zi" /> Appointments</span>
                            <span><x-icon name="package" class="zi" /> Stock</span>
                        </div>
                        <div class="z-shot-main">
                            <div class="z-shot-head">
                                <div><b>Good morning, Rudo</b><small>Here is what is happening at Sunrise Clinic today.</small></div>
                                <em>New invoice</em>
                            </div>
                            <div class="z-shot-kpis">
                                <div><small>Invoiced this month</small><b>$4,120</b><em class="up">+18%</em></div>
                                <div><small>Owed to you</small><b>$860</b><em>3 overdue</em></div>
                                <div><small>Bookings today</small><b>14</b><em>2 waiting</em></div>
                            </div>
                            <div class="z-shot-table">
                                <div><span>Invoice</span><span>Customer</span><span>Status</span></div>
                                <div><span>INV-0312</span><span>Mwale Pharmacy</span><i class="ok">Paid</i></div>
                                <div><span>INV-0311</span><span>Grace Academy</span><i class="info">Sent</i></div>
                                <div><span>INV-0309</span><span>Kamuzu Farms</span><i class="warn">Due</i></div>
                                <div><span>INV-0308</span><span>T. Banda</span><i class="ok">Paid</i></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
