<?php

namespace App\Http\Controllers\Settings;

use App\Assistant\AssistantProvider;
use App\Assistant\AssistantService;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Choose the assistant's AI provider, store its key and set the monthly question limit. */
class AssistantSettingsController extends Controller
{
    public function __construct(protected WorkspaceContext $context, protected AssistantService $assistant) {}

    public function edit(): View
    {
        $workspace = $this->context->getOrFail();

        return view('settings.assistant', [
            'current' => $workspace->setting('assistant.provider'),
            'limit' => $this->assistant->monthlyLimit($workspace),
            'usage' => $this->assistant->usage($workspace),
            'providers' => collect($this->assistant->providers())->map(fn (AssistantProvider $provider) => [
                'provider' => $provider,
                'configured' => $this->assistant->isConfigured($workspace, $provider),
                'values' => collect($this->assistant->credentials($workspace, $provider))
                    ->map(fn (string $value, string $field) => $provider->fields()[$field]['secret'] ? ($value === '' ? null : '••••'.substr($value, -4)) : $value)
                    ->all(),
            ])->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->context->getOrFail();
        $rules = [
            'provider' => ['nullable', Rule::in(array_keys($this->assistant->providers()))],
            'monthly_limit' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
        foreach ($this->assistant->providers() as $key => $provider) {
            foreach (array_keys($provider->fields()) as $field) {
                $rules[$key.'.'.$field] = ['nullable', 'string', 'max:255'];
            }
        }
        $data = $request->validate($rules);

        $values = collect($this->assistant->providers())->map(fn (AssistantProvider $provider, string $key) => $data[$key] ?? [])->all();
        $this->assistant->configure($workspace, $data['provider'] ?? null, $values, (int) $data['monthly_limit']);

        $workspace->refresh();
        Audit::log('settings', 'assistant-updated', 'Updated the AI assistant settings', properties: [
            'provider' => $workspace->setting('assistant.provider'),
            'monthly_limit' => $workspace->setting('assistant.monthly_limit'),
        ]);
        $chosen = $this->assistant->find($workspace->setting('assistant.provider'));
        if ($chosen && ! $this->assistant->isConfigured($workspace, $chosen)) {
            return back()->with('flash', ['type' => 'warning', 'message' => 'Saved, but '.$chosen->label().' needs its API key before the assistant can answer.']);
        }

        return back()->with('flash', ['type' => 'success', 'message' => $chosen ? 'Assistant settings saved.' : 'The assistant is switched off.']);
    }
}
