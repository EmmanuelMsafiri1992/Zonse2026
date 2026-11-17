@extends('layouts.app')
@section('title', 'LinkedIn posts')
@section('content')
    <x-page-header title="LinkedIn posts" sub="The same series as the Facebook Page, on the Zonseob LinkedIn Page: an introduction to the platform, then every suite and every app in turn, each with its card." />

    @if(! $configured)
        <div class="alert alert-warning d-flex gap-2 align-items-center">
            <x-icon name="plug-zap" class="zi" />
            <div class="fs-7">The LinkedIn Page is not connected yet. Paste an access token in the settings on the right.</div>
        </div>
    @elseif($settings['token_expires_at'] && $settings['token_expires_at'] <= now()->addDays(7)->toDateString())
        <div class="alert alert-warning d-flex gap-2 align-items-center">
            <x-icon name="clock" class="zi" />
            <div class="fs-7">The LinkedIn token runs out about {{ $settings['token_expires_at'] }}. Make a new one and paste it in the settings.</div>
        </div>
    @elseif(! $settings['enabled'])
        <div class="alert alert-info d-flex gap-2 align-items-center">
            <x-icon name="pause" class="zi" />
            <div class="fs-7">Connected, but automatic posting is switched off. Turn it on in the settings, or use "Post next now" to publish one post by hand.</div>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="row g-3 mb-4">
                <div class="col-sm-4">
                    <div class="card h-100"><div class="card-body">
                        <div class="fs-8 text-muted text-uppercase">Series</div>
                        <div class="fs-3 fw-semibold">{{ $total - $remainingCount }} <span class="fs-6 text-muted">of {{ $total }} posted</span></div>
                        <div class="progress mt-2" style="height:6px"><div class="progress-bar" style="width: {{ $total ? round(($total - $remainingCount) / $total * 100) : 0 }}%"></div></div>
                    </div></div>
                </div>
                <div class="col-sm-4">
                    <div class="card h-100"><div class="card-body">
                        <div class="fs-8 text-muted text-uppercase">Next post goes out</div>
                        <div class="fs-5 fw-semibold">
                            @if(! $settings['enabled'] || ! $configured) Not scheduled
                            @elseif($nextSlot) Now ({{ $nextSlot->format('H:i') }} slot is due)
                            @else At {{ collect(explode(',', $settings['times']))->first(fn ($t) => $t > now($settings['timezone'])->format('H:i')) ?? explode(',', $settings['times'])[0].' tomorrow' }}
                            @endif
                        </div>
                        <div class="fs-8 text-muted">{{ $settings['times'] }} · {{ $settings['timezone'] }}</div>
                    </div></div>
                </div>
                <div class="col-sm-4">
                    <div class="card h-100"><div class="card-body">
                        <div class="fs-8 text-muted text-uppercase">Failed attempts</div>
                        <div class="fs-3 fw-semibold {{ $failed ? 'text-danger' : '' }}">{{ $failed }}</div>
                        <div class="fs-8 text-muted">A failed post is retried at the next posting time.</div>
                    </div></div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header d-flex align-items-center gap-2">
                    <h5 class="card-title mb-0">Coming up</h5>
                    @if($configured)
                        <form method="POST" action="{{ route('admin.linkedin.post-now') }}" class="ms-auto" onsubmit="return confirm('Publish the next post to the LinkedIn Page now?')">
                            @csrf
                            <button class="btn btn-sm btn-primary"><x-icon name="send" class="zi zi-sm" /> Post next now</button>
                        </form>
                    @endif
                </div>
                <div class="z-table-wrap">
                    <table class="table z-table">
                        <thead><tr><th style="width:72px">Card</th><th>Post</th><th>Kind</th></tr></thead>
                        <tbody>
                        @forelse($upcoming as $i => $draft)
                            <tr>
                                <td>
                                    @if($cardsAvailable)
                                        <a href="{{ route('admin.facebook.card', [$draft->kind, $draft->key]) }}" target="_blank" title="Open the card">
                                            <img src="{{ route('admin.facebook.card', [$draft->kind, $draft->key]) }}" alt="" width="56" height="56" class="rounded" loading="lazy">
                                        </a>
                                    @else
                                        <span class="text-muted fs-8">link only</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="z-row-title">{{ $i === 0 ? 'Next: ' : '' }}{{ $draft->title }}</div>
                                    <div class="fs-8 text-muted">{{ $draft->eyebrow }}</div>
                                </td>
                                <td><x-pill :status="$draft->kind" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><x-empty icon="check-circle" title="Series complete" text="Every post in the series has been published on LinkedIn." /></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if($remainingCount > $upcoming->count())
                    <div class="card-footer fs-8 text-muted">{{ $remainingCount - $upcoming->count() }} more after these.</div>
                @endif
            </div>

            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Posted</h5></div>
                <div class="z-table-wrap">
                    <table class="table z-table">
                        <thead><tr><th>When</th><th>Post</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        @forelse($posts as $post)
                            <tr>
                                <td class="fs-7">{{ ($post->posted_at ?? $post->created_at)->timezone($settings['timezone'])->format('d M Y, H:i') }}</td>
                                <td>
                                    <div class="z-row-title">{{ $post->title }}</div>
                                    <div class="fs-8 text-muted">{{ $post->kind }} · {{ $post->key }}</div>
                                    @if($post->error)<div class="fs-8 text-danger">{{ $post->error }}</div>@endif
                                </td>
                                <td><x-pill :status="$post->status" /></td>
                                <td class="text-end">@if($post->permalink())<a href="{{ $post->permalink() }}" target="_blank" rel="noopener" class="btn btn-sm btn-white">Open</a>@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><x-empty icon="megaphone" title="Nothing posted yet" text="Posts appear here as they go out." /></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <form method="POST" action="{{ route('admin.linkedin.settings') }}" class="card mb-4" autocomplete="off">
                @csrf @method('PUT')
                <div class="card-header"><h5 class="card-title">Settings</h5></div>
                <div class="card-body">
                    <x-form.input name="organization_id" label="LinkedIn Page ID" :value="$settings['organization_id']" help="Leave blank to take it from the token. It is the number in the Page admin URL." />
                    <x-form.textarea name="token" label="Access token" rows="3" :help="$settings['has_token'] ? 'A token is stored, it runs out about '.$settings['token_expires_at'].'. Paste a new one only to replace it.' : 'From the LinkedIn developer portal OAuth token generator for the Zonseob Auto Poster app, with w_organization_social and rw_organization_admin. It lasts 60 days.'" />
                    <x-form.input name="times" label="Posting times" :value="$settings['times']" required help="24-hour times separated by commas, in the Facebook page's timezone. One post goes out per time." />
                    <x-form.check name="enabled" label="Post automatically" :checked="$settings['enabled']" switch help="Off keeps the connection but publishes nothing on its own." />
                </div>
                <div class="card-footer d-flex gap-2">
                    @if($configured)
                        <button class="btn btn-white btn-sm" form="li-check-form">Check token</button>
                        <button class="btn btn-sm btn-soft-danger" form="li-disconnect-form">Disconnect</button>
                    @endif
                    <button class="btn btn-primary ms-auto">Save</button>
                </div>
            </form>
            <form id="li-check-form" method="POST" action="{{ route('admin.linkedin.check') }}" class="d-none">@csrf</form>
            <form id="li-disconnect-form" method="POST" action="{{ route('admin.linkedin.disconnect') }}" class="d-none" onsubmit="return confirm('Remove the stored LinkedIn token and stop posting?')">@csrf</form>

            <div class="card">
                <div class="card-header"><h5 class="card-title">How it works</h5></div>
                <div class="card-body fs-7 text-muted">
                    <p>The order and the text are the Facebook series, with its own progress here, so each Page gets every post once.</p>
                    <p class="mb-0">Posting runs from the scheduler (<code>zonseo:post-to-linkedin</code>), after normal site traffic and from the Facebook tick URL. A failed attempt is retried at the next posting time.</p>
                </div>
            </div>
        </div>
    </div>
@endsection
