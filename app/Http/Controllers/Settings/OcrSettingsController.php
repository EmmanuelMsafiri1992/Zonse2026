<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Ocr\OcrProvider;
use App\Ocr\OcrService;
use App\Support\Audit;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Choose how scanned receipts, invoices and ID documents are read, and store the provider's key. */
class OcrSettingsController extends Controller
{
    public function __construct(protected WorkspaceContext $context, protected OcrService $ocr) {}

    public function edit(): View
    {
        $workspace = $this->context->getOrFail();

        return view('settings.ocr', [
            'current' => $workspace->setting('ocr.provider'),
            'providers' => collect($this->ocr->providers())->map(fn (OcrProvider $provider) => [
                'provider' => $provider,
                'configured' => $this->ocr->isConfigured($workspace, $provider),
                'values' => collect($this->ocr->credentials($workspace, $provider))
                    ->map(fn (string $value, string $field) => $provider->fields()[$field]['secret'] ? ($value === '' ? null : '••••'.substr($value, -4)) : $value)
                    ->all(),
            ])->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->context->getOrFail();
        $rules = ['provider' => ['nullable', Rule::in(array_keys($this->ocr->providers()))]];
        foreach ($this->ocr->providers() as $key => $provider) {
            foreach (array_keys($provider->fields()) as $field) {
                $rules[$key.'.'.$field] = ['nullable', 'string', 'max:255'];
            }
        }
        $data = $request->validate($rules);

        $values = collect($this->ocr->providers())->map(fn (OcrProvider $provider, string $key) => $data[$key] ?? [])->all();
        $this->ocr->configure($workspace, $data['provider'] ?? null, $values);

        $workspace->refresh();
        Audit::log('settings', 'ocr-updated', 'Updated the document capture settings', properties: ['provider' => $workspace->setting('ocr.provider')]);
        $chosen = $this->ocr->find($workspace->setting('ocr.provider'));
        if ($chosen && ! $this->ocr->isConfigured($workspace, $chosen)) {
            return back()->with('flash', ['type' => 'warning', 'message' => 'Saved, but '.$chosen->label().' needs its key before documents can be read.']);
        }

        return back()->with('flash', ['type' => 'success', 'message' => $chosen ? 'Document capture settings saved.' : 'Document capture is switched off.']);
    }
}
