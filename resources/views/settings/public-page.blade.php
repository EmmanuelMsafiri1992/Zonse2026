@extends('layouts.app')
@section('title', 'Public page')
@section('content')
    <x-page-header title="Public page" sub="One link for your bio, website or WhatsApp status: people book a time, order or pay you without an account." :crumbs="['Settings' => route('settings.workspace.edit'), 'Public page']" />

    <form method="POST" action="{{ route('settings.public-page.update') }}">
        @csrf
        @method('PUT')
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-header"><h5 class="card-title mb-0">Page</h5></div>
                    <div class="card-body">
                        <x-form.check name="enabled" label="The page is live" :checked="$settings['enabled']" help="When off, the link shows “not found”." switch />
                        <x-form.input name="headline" label="Headline" :value="$settings['headline']" maxlength="120" placeholder="{{ $workspace->name }}" />
                        <x-form.textarea name="bio" label="Short description" :value="$settings['bio']" rows="3" maxlength="500" placeholder="What you do, where you are, opening hours…" />
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header"><h5 class="card-title mb-0">Buttons</h5></div>
                    <div class="card-body">
                        @foreach(\App\Support\PublicPage::BLOCKS as $key => $block)
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="blocks[]" value="{{ $key }}" id="block_{{ $key }}" @checked(in_array($key, old('blocks', $settings['blocks']), true)) @disabled(! isset($available[$key]))>
                                <label class="form-check-label" for="block_{{ $key }}">{{ $block['label'] }}</label>
                                @unless(isset($available[$key]))
                                    <span class="d-block fs-8 text-muted">{{ match ($key) {
                                        'booking' => 'Needs the Appointments app and at least one active service.',
                                        'ordering' => 'Needs the Invoicing app and at least one priced item in stock.',
                                        default => 'Needs an online payment method under Settings › Invoicing.',
                                    } }}</span>
                                @endunless
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header"><h5 class="card-title mb-0">Links</h5></div>
                    <div class="card-body">
                        @for($i = 0; $i < \App\Support\PublicPage::MAX_LINKS; $i++)
                            <div class="row g-2">
                                <div class="col-sm-4"><x-form.input name="links[{{ $i }}][label]" :value="$settings['links'][$i]['label'] ?? null" placeholder="Label, e.g. Instagram" maxlength="60" aria-label="Link {{ $i + 1 }} label" /></div>
                                <div class="col-sm-8"><x-form.input name="links[{{ $i }}][url]" type="url" :value="$settings['links'][$i]['url'] ?? null" placeholder="https://" maxlength="255" aria-label="Link {{ $i + 1 }} address" /></div>
                            </div>
                        @endfor
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-header"><h5 class="card-title mb-0">Your link</h5></div>
                    <div class="card-body">
                        <input type="text" class="form-control form-control-sm" value="{{ $pageUrl }}" readonly onclick="this.select()" aria-label="Public page link">
                        <a href="{{ $pageUrl }}" target="_blank" rel="noopener" class="btn btn-white btn-sm mt-2"><x-icon name="external-link" class="zi zi-sm" /> Open the page</a>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header"><h5 class="card-title mb-0">Online bookings</h5></div>
                    <div class="card-body">
                        <x-form.input name="min_notice_hours" type="number" min="0" max="720" label="Minimum notice (hours)" :value="$settings['min_notice_hours']" required />
                        <x-form.input name="max_days_ahead" type="number" min="1" max="365" label="Book up to (days ahead)" :value="$settings['max_days_ahead']" required />
                        <x-form.input name="capacity" type="number" min="1" max="50" label="Bookings at the same time" :value="$settings['capacity']" required help="How many people you can see at once. Opening hours and slot length come from Booking settings." />
                    </div>
                </div>

                <button class="btn btn-primary w-100">Save</button>
            </div>
        </div>
    </form>
@endsection
