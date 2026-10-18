@extends('layouts.app')
@section('title', 'Facebook posts')
@section('content')
    <x-page-header title="Facebook posts" sub="The series that explains Zonseob on its Facebook Page: an introduction to the platform, then every suite and every app in turn. Posts go out automatically at the times below." />

    @if(! $configured)
        <div class="alert alert-warning d-flex gap-2 align-items-center">
            <x-icon name="plug-zap" class="zi" />
            <div class="fs-7">The Page is not connected yet. Save the Page ID, then connect with a token from Graph API Explorer in the panel on the right.</div>
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
                        <form method="POST" action="{{ route('admin.facebook.post-now') }}" class="ms-auto" onsubmit="return confirm('Publish the next post to the Page now?')">
                            @csrf
                            <button class="btn btn-sm btn-primary"><x-icon name="send" class="zi zi-sm" /> Post next now</button>
                        </form>
                    @endif
                </div>
                <div class="z-table-wrap">
                    <table class="table z-table">
                        <thead><tr><th style="width:72px">Card</th><th>Post</th><th>Kind</th><th></th></tr></thead>
                        <tbody>
                        @forelse($upcoming as $i => $draft)
                            <tr>
                                <td>
                                    @if($cardsAvailable)
                                        <a href="{{ route('admin.facebook.card', [$draft->kind, $draft->key]) }}" target="_blank" title="Open the card">
                                            <img src="{{ route('admin.facebook.card', [$draft->kind, $draft->key]) }}" alt="" width="56" height="56" class="rounded" loading="lazy">
                                        </a>
                                    @else
                                        <span class="text-muted fs-8">text only</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="z-row-title">{{ $i === 0 ? 'Next: ' : '' }}{{ $draft->title }}</div>
                                    <div class="fs-8 text-muted">{{ $draft->eyebrow }}</div>
                                </td>
                                <td><x-pill :status="$draft->kind" /></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-white" data-bs-toggle="collapse" data-bs-target="#draft-{{ $i }}">Read</button>
                                </td>
                            </tr>
                            <tr class="collapse" id="draft-{{ $i }}"><td colspan="4" class="bg-light"><pre class="mb-0 fs-7" style="white-space:pre-wrap;font-family:inherit">{{ $draft->message }}</pre></td></tr>
                        @empty
                            <tr><td colspan="4"><x-empty icon="check-circle" title="Series complete" text="Every post in the series has been published." /></td></tr>
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
            <form method="POST" action="{{ route('admin.facebook.settings') }}" class="card mb-4">
                @csrf @method('PUT')
                <div class="card-header"><h5 class="card-title">Settings</h5></div>
                <div class="card-body">
                    <x-form.input name="page_id" label="Facebook Page ID" :value="$settings['page_id']" required help="The number in the Page URL: facebook.com/profile.php?id=…" />
                    <x-form.input name="page_token" type="password" label="Page access token" autocomplete="off" :help="$settings['has_token'] ? 'A token is stored'.($settings['token_source'] === 'env' ? ' in the server environment' : '').'. Paste a new one only to replace it.' : 'Paste a long-lived Page token, or use Connect below to make one.'" />
                    <x-form.input name="times" label="Posting times" :value="$settings['times']" required help="24-hour times separated by commas. One post goes out per time." />
                    <x-form.input name="timezone" label="Timezone" :value="$settings['timezone']" required list="tz-list" />
                    <datalist id="tz-list">@foreach(timezone_identifiers_list() as $tz)<option value="{{ $tz }}">@endforeach</datalist>
                    <x-form.check name="enabled" label="Post automatically" :checked="$settings['enabled']" switch help="Off keeps the connection but publishes nothing on its own." />
                </div>
                <div class="card-footer d-flex gap-2">
                    @if($configured)
                        <button class="btn btn-white btn-sm" form="check-form">Check token</button>
                    @endif
                    <button class="btn btn-primary ms-auto">Save</button>
                </div>
            </form>
            <form id="check-form" method="POST" action="{{ route('admin.facebook.check') }}" class="d-none">@csrf</form>
            <form id="disconnect-form" method="POST" action="{{ route('admin.facebook.disconnect') }}" class="d-none" onsubmit="return confirm('Remove the stored token and stop posting?')">@csrf</form>

            <form method="POST" action="{{ route('admin.facebook.connect') }}" class="card mb-4" autocomplete="off">
                @csrf
                <div class="card-header"><h5 class="card-title">Connect with Graph API Explorer</h5></div>
                <div class="card-body fs-7">
                    <ol class="ps-3 text-muted mb-3">
                        <li>At developers.facebook.com create (or open) an app and copy its App ID and App secret.</li>
                        <li>Open Tools, Graph API Explorer. Choose the app, choose "Get Page access token", pick this Page and grant <code>pages_manage_posts</code>, <code>pages_read_engagement</code> and <code>pages_show_list</code>.</li>
                        <li>Copy the token it shows and paste the three values here. Zonseob exchanges it for a Page token that does not expire.</li>
                    </ol>
                    <x-form.input name="app_id" label="App ID" />
                    <x-form.input name="app_secret" type="password" label="App secret" autocomplete="new-password" />
                    <x-form.textarea name="user_token" label="Token from Graph API Explorer" rows="3" />
                </div>
                <div class="card-footer d-flex gap-2">
                    @if($settings['has_token'] && $settings['token_source'] === 'settings')
                        <button class="btn btn-sm btn-soft-danger" form="disconnect-form">Disconnect</button>
                    @endif
                    <button class="btn btn-primary ms-auto">Connect</button>
                </div>
            </form>

            <div class="card">
                <div class="card-header"><h5 class="card-title">How the series works</h5></div>
                <div class="card-body fs-7 text-muted">
                    <p>The order is fixed: {{ count(app(\App\Social\PostWriter::class)->intros(0, 0)) }} posts introducing the platform, then each suite in catalogue order, each followed by one post per app. Text comes from the catalogue and the app blueprints, so it stays accurate as apps change.</p>
                    <p>Every post carries a branded card. Click a thumbnail above to see it at full size before it goes out.</p>
                    <p>Posting runs from the scheduler (<code>zonseo:post-to-facebook</code>) and, as a fallback, after normal site traffic (checked at most every five minutes). Nothing is ever posted twice; a failed attempt is retried at the next time.</p>
                    <p class="mb-0">If the server's cron is not running, have any uptime monitor or external cron open this URL every few minutes:<br><code class="user-select-all text-break">{{ $tickUrl }}</code></p>
                </div>
            </div>
        </div>
    </div>
@endsection
