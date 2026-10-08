@extends('layouts.app')
@section('title', 'Hardware')
@section('content')
    <x-page-header title="Hardware" sub="Receipt printers, barcode scanners, weighing scales and card terminals at the counter." :crumbs="['Settings' => route('settings.workspace.edit'), 'Hardware']">
        @if($tillReady)<a href="{{ route('apps.pos.till') }}" class="btn btn-white"><x-icon name="monitor-smartphone" /> Open the till</a>@endif
    </x-page-header>

    <form method="POST" action="{{ route('settings.hardware.update') }}">
        @csrf
        @method('PUT')
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-header"><h5 class="card-title mb-0"><x-icon name="printer" /> Receipt printer</h5></div>
                    <div class="card-body">
                        <x-form.select name="receipt_width" label="Paper" :options="\App\Support\Hardware\HardwareSettings::PAPER_WIDTHS" :value="$settings['receipt_width']" required />
                        <x-form.textarea name="receipt_header" label="Lines under your name" :value="$settings['receipt_header']" rows="3" maxlength="300" placeholder="Address, phone, tax number…" />
                        <x-form.check name="open_drawer" label="Open the cash drawer after cash sales" :checked="$settings['open_drawer']" help="For drawers plugged into the receipt printer." switch />
                        <x-form.check name="receipt_qr" label="Print a QR code linking to the invoice" :checked="$settings['receipt_qr']" switch />
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header"><h5 class="card-title mb-0"><x-icon name="scale" /> Weighing scale labels</h5></div>
                    <div class="card-body">
                        <p class="fs-7 text-muted">Scales that print price labels put the item number and weight (or price) in the barcode. Set each weighed item's SKU to its scale item number.</p>
                        <div class="row">
                            <div class="col-md-6"><x-form.input name="scale_prefixes" label="Barcode prefixes" :value="implode(', ', $settings['scale_prefixes'])" placeholder="21, 22" help="Leave blank if you have no label scale." /></div>
                            <div class="col-md-6"><x-form.select name="scale_mode" label="The label barcode carries" :options="\App\Support\Hardware\HardwareSettings::SCALE_MODES" :value="$settings['scale_mode']" required /></div>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header"><h5 class="card-title mb-0"><x-icon name="credit-card" /> Card terminal</h5></div>
                    <div class="card-body">
                        <x-form.select name="card_terminal" label="Linked terminal" :options="\App\Support\Hardware\HardwareSettings::CARD_TERMINALS" :value="$settings['card_terminal']" required
                                       help="The test terminal approves card sales at the till, except amounts ending in .51, which it declines." />
                    </div>
                </div>

                <button class="btn btn-primary">Save</button>
            </div>

            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">Connecting devices</h5></div>
                    <div class="card-body fs-7">
                        <p><strong><x-icon name="scan-barcode" /> Barcode scanners</strong><br>Any USB or Bluetooth scanner that types like a keyboard works. Scan into the till's search box. Give items barcodes under Products &amp; services, and print labels there.</p>
                        <p><strong><x-icon name="printer" /> Receipt printers</strong><br>Print the receipt slip from the browser, or in Chrome or Edge send it straight to a USB-serial printer. The ESC/POS file works with printer apps on Android.</p>
                        <p><strong><x-icon name="scale" /> Scales</strong><br>Label scales need no cable. Scales on a serial or USB cable can be read with <em>Read scale</em> on the till in Chrome or Edge.</p>
                        <p class="mb-0"><strong><x-icon name="qr-code" /> QR codes</strong><br>Receipts and labels carry QR codes customers and staff can scan with a phone.</p>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection
