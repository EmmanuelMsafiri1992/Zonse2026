@extends('layouts.app')
@section('title', 'Phone access')
@section('content')
    <x-page-header title="Phone access" sub="Use Zonseob from any mobile phone by dialling a USSD code, no smartphone or data needed." />

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card mb-4">
                <div class="card-header"><h5 class="card-title">Your phone PIN</h5></div>
                <div class="card-body">
                    @if(! $enabled)
                        <div class="alert alert-light border fs-7">Phone access is switched off for this workspace{{ $canConfigure ? '. Switch it on below.' : '. Ask an owner or admin to switch it on.' }}</div>
                    @elseif($serviceCode)
                        <p class="fs-7">Dial <strong class="font-monospace">{{ $serviceCode }}</strong> from the number on your profile, then enter your PIN.</p>
                    @endif

                    @if(! $phone)
                        <x-empty icon="smartphone" title="Add your mobile number first" text="The phone menu recognises you by the number saved on your profile." class="py-2">
                            <a href="{{ route('profile.edit') }}" class="btn btn-primary">Edit profile</a>
                        </x-empty>
                    @else
                        <p class="fs-7 text-muted">Calls from <strong class="text-body">{{ $phone }}</strong> are linked to you.
                            {{ $membership->ussd_pin ? 'Your PIN is set.' : 'You have not set a PIN yet, so this number cannot use the menu.' }}</p>
                        <form method="POST" action="{{ route('ussd.pin.update') }}">
                            @csrf @method('PUT')
                            <div class="row">
                                <div class="col-md-4"><x-form.input name="pin" type="password" inputmode="numeric" maxlength="4" :label="$membership->ussd_pin ? 'New 4-digit PIN' : '4-digit PIN'" autocomplete="new-password" required /></div>
                                <div class="col-md-4"><x-form.input name="pin_confirmation" type="password" inputmode="numeric" maxlength="4" label="Repeat PIN" autocomplete="new-password" required /></div>
                                <div class="col-md-4"><x-form.input name="current_password" type="password" label="Your password" autocomplete="current-password" required /></div>
                            </div>
                            <div class="d-flex justify-content-end gap-2">
                                <button class="btn btn-primary"><x-icon name="check" /> {{ $membership->ussd_pin ? 'Change PIN' : 'Save PIN' }}</button>
                            </div>
                        </form>
                        @if($membership->ussd_pin)
                            <form method="POST" action="{{ route('ussd.pin.destroy') }}" class="mt-2 text-end" onsubmit="return confirm('Remove your phone PIN? This number will stop working with the phone menu.')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-link text-danger p-0">Remove my PIN</button>
                            </form>
                        @endif
                    @endif
                </div>
            </div>

            @if($canConfigure)
                <form method="POST" action="{{ route('settings.ussd.update') }}" class="card mb-4">
                    @csrf @method('PUT')
                    <div class="card-header"><h5 class="card-title">Set up for the workspace</h5></div>
                    <div class="card-body">
                        <x-form.check name="enabled" label="Let members use the phone menu" :checked="$enabled" switch />
                        <x-form.input name="service_code" label="USSD code" :value="$serviceCode" placeholder="*384*123#"
                                      help="The code your gateway gave you, shown to members on this page." />
                        @if($callbackUrl)
                            <label class="form-label">Callback URL</label>
                            <div class="input-group mb-1" x-data="{ copied: false }">
                                <input type="text" class="form-control font-monospace fs-8" value="{{ $callbackUrl }}" readonly x-ref="url">
                                <button type="button" class="btn btn-white" @click="navigator.clipboard.writeText($refs.url.value).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                                    <x-icon name="copy" /> <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                                </button>
                            </div>
                            <div class="form-text mb-3">Paste this into your USSD gateway (Africa's Talking or any gateway using the same format) as the callback URL. Keep it private.</div>
                        @else
                            <div class="form-text mb-3">Save once to get the callback URL for your gateway.</div>
                        @endif
                    </div>
                    <div class="card-footer d-flex justify-content-between">
                        @if($callbackUrl)
                            <button class="btn btn-white" form="rotate-ussd-token"><x-icon name="refresh-cw" /> New callback URL</button>
                        @else
                            <span></span>
                        @endif
                        <button class="btn btn-primary"><x-icon name="check" /> Save settings</button>
                    </div>
                </form>
                <form method="POST" action="{{ route('settings.ussd.token') }}" id="rotate-ussd-token" onsubmit="return confirm('Make a new callback URL? The old one stops working straight away.')">@csrf</form>
            @endif
        </div>

        <div class="col-lg-5">
            @if($canConfigure)
                <div class="card mb-3" x-data="ussdSimulator(@js(route('settings.ussd.simulate')), @js($phone ?? ''))">
                    <div class="card-header"><h5 class="card-title">Try it</h5></div>
                    <div class="card-body">
                        <p class="fs-8 text-muted">Runs the real menu against this workspace, as if the number below had dialled in. Nothing goes through a gateway.</p>
                        <div class="input-group mb-3">
                            <input type="text" class="form-control" x-model="phone" placeholder="Mobile number" aria-label="Mobile number">
                            <button type="button" class="btn btn-primary" @click="dial()"><x-icon name="phone" /> Dial</button>
                        </div>
                        <template x-if="screen !== null">
                            <div class="border rounded-3 p-3 bg-body-tertiary" id="ussd-screen">
                                <div class="font-monospace fs-7 mb-2" style="white-space:pre-wrap" x-text="screen"></div>
                                <template x-if="open">
                                    <form @submit.prevent="send()" class="d-flex gap-2">
                                        <input type="text" class="form-control form-control-sm" x-model="answer" x-ref="answer" aria-label="Reply">
                                        <button class="btn btn-sm btn-primary">Send</button>
                                    </form>
                                </template>
                                <div x-show="! open" class="fs-8 text-muted">Session ended.</div>
                            </div>
                        </template>
                    </div>
                </div>
                <script>
                    function ussdSimulator(url, phone) {
                        return {
                            phone, inputs: [], answer: '', screen: null, open: false,
                            dial() { this.inputs = []; this.request(); },
                            send() { this.inputs.push(this.answer.replaceAll('*', '')); this.answer = ''; this.request(); },
                            request() {
                                fetch(url, {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                    body: JSON.stringify({ phone: this.phone, text: this.inputs.join('*') }),
                                }).then(response => response.json()).then(data => {
                                    if (! data.reply) { this.screen = 'Enter a mobile number to dial from.'; this.open = false; return; }
                                    this.open = data.reply.startsWith('CON ');
                                    this.screen = data.reply.substring(4);
                                    this.$nextTick(() => this.$refs.answer?.focus());
                                });
                            },
                        };
                    }
                </script>

                @if($loggable)
                    <div class="card mb-3">
                        <div class="card-header"><h5 class="card-title">Lists that take new records by phone</h5></div>
                        <div class="card-body fs-7">{{ collect($loggable)->pluck('plural')->implode(', ') }}</div>
                    </div>
                @endif
            @endif

            <div class="card">
                <div class="card-header"><h5 class="card-title">What you can do from a phone</h5></div>
                <div class="card-body fs-7">
                    <ol class="ps-3 mb-0">
                        <li class="mb-2">See the open items assigned to you or that you logged.</li>
                        <li class="mb-2">Log a new record, such as an expense or a job, by answering a few short questions.</li>
                        <li class="mb-2">Find any record by its number, move it to a new status or add a note.</li>
                        <li>Viewers can look records up but cannot change anything. Five wrong PINs lock the number for 15 minutes.</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
@endsection
