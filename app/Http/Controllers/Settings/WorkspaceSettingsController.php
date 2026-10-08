<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Lists;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class WorkspaceSettingsController extends Controller
{
    public function __construct(protected WorkspaceContext $context) {}

    public function edit()
    {
        return view('settings.workspace', [
            'workspace' => $this->context->getOrFail(),
            'types' => Lists::WORKSPACE_TYPES,
            'countries' => Lists::COUNTRIES,
            'currencies' => Lists::CURRENCIES,
            'timezones' => Lists::timezones(),
        ]);
    }

    public function update(Request $request)
    {
        $workspace = $this->context->getOrFail();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(array_keys(Lists::WORKSPACE_TYPES))],
            'email' => ['nullable', 'email', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'url', 'max:160'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
            'country_code' => ['required', Rule::in(array_keys(Lists::COUNTRIES))],
            'currency_code' => ['required', Rule::in(array_keys(Lists::CURRENCIES))],
            'timezone' => ['required', 'timezone:all'],
            'tax_number' => ['nullable', 'string', 'max:60'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('remove_logo') && $workspace->logo_path) {
            Storage::disk('public')->delete($workspace->logo_path);
            $data['logo_path'] = null;
        }
        if ($request->hasFile('logo')) {
            if ($workspace->logo_path) {
                Storage::disk('public')->delete($workspace->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('workspaces/'.$workspace->id, 'public');
        }
        unset($data['logo'], $data['remove_logo']);

        $workspace->update($data);

        return back()->with('flash', ['type' => 'success', 'message' => 'Workspace settings saved.']);
    }
}
