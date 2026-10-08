<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Sms\SmsProvider;
use App\Sms\SmsService;
use App\Support\Audit;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Choose the workspace's SMS provider, store its credentials, switch automations on and send a test text. */
class SmsSettingsController extends Controller
{
    public function __construct(protected WorkspaceContext $context, protected SmsService $sms) {}

    public function edit(): View
    {
        $workspace = $this->context->getOrFail();

        return view('settings.sms', [
            'workspace' => $workspace,
            'current' => $workspace->setting('sms.provider'),
            'active' => $this->sms->provider($workspace),
            'automations' => SmsService::AUTOMATIONS,
            'providers' => collect($this->sms->providers())->map(fn (SmsProvider $provider) => [
                'provider' => $provider,
                'configured' => $this->sms->isConfigured($workspace, $provider),
                'values' => collect($this->sms->credentials($workspace, $provider))
                    ->map(fn (string $value, string $field) => $provider->fields()[$field]['secret'] ? ($value === '' ? null : '••••'.substr($value, -4)) : $value)
                    ->all(),
            ])->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->context->getOrFail();
        $rules = ['provider' => ['nullable', Rule::in(array_keys($this->sms->providers()))]];
        foreach ($this->sms->providers() as $key => $provider) {
            foreach (array_keys($provider->fields()) as $field) {
                $rules[$key.'.'.$field] = ['nullable', 'string', 'max:255'];
            }
        }
        foreach (array_keys(SmsService::AUTOMATIONS) as $automation) {
            $rules['notify.'.$automation] = ['nullable', 'boolean'];
        }
        $data = $request->validate($rules);

        $values = collect($this->sms->providers())->map(fn (SmsProvider $provider, string $key) => $data[$key] ?? [])->all();
        $this->sms->configure($workspace, $data['provider'] ?? null, $values, $data['notify'] ?? []);

        $workspace->refresh();
        Audit::log('settings', 'sms-updated', 'Updated the text message (SMS) settings', properties: ['provider' => $workspace->setting('sms.provider')]);
        $chosen = $this->sms->find($workspace->setting('sms.provider'));
        if ($chosen && ! $this->sms->isConfigured($workspace, $chosen)) {
            return back()->with('flash', ['type' => 'warning', 'message' => 'Saved, but '.$chosen->label().' needs its credentials before any text can be sent.']);
        }

        return back()->with('flash', ['type' => 'success', 'message' => $chosen ? 'SMS settings saved.' : 'SMS is switched off.']);
    }

    /** Send a short test message so the owner can confirm the credentials and sender ID work. */
    public function test(Request $request): RedirectResponse
    {
        $workspace = $this->context->getOrFail();
        $data = $request->validate(['to' => ['required', 'string', 'max:30']]);

        if (! $this->sms->enabled($workspace)) {
            return back()->with('flash', ['type' => 'danger', 'message' => 'Choose a provider and save its credentials first.']);
        }

        $message = $this->sms->send($workspace, $data['to'], SmsService::prefix($workspace).'this is a test message from Zonseo. SMS is working.', ['purpose' => 'test']);
        if (! $message) {
            return back()->withInput()->withErrors(['to' => 'Enter a valid mobile number, e.g. 0771234567 or +263771234567.']);
        }

        $message->refresh();

        return back()->with('flash', match ($message->status) {
            'sent' => ['type' => 'success', 'message' => 'Test message sent to '.$message->to.'.'],
            'failed' => ['type' => 'danger', 'message' => 'The provider refused the message: '.$message->error],
            default => ['type' => 'info', 'message' => 'Test message queued for '.$message->to.'. Check the message history in a minute.'],
        });
    }
}
