<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\WebhookEndpoint;
use App\Support\Audit;
use App\Support\Webhooks;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** API keys for this workspace, plus the list of webhook endpoints. */
class ApiKeyController extends Controller
{
    /** Live keys a workspace may hold at once. */
    public const MAX_KEYS = 25;

    public function __construct(protected WorkspaceContext $context) {}

    public function index(): View
    {
        return view('settings.api', [
            'tokens' => ApiToken::where('workspace_id', $this->context->id())->with('tokenable')->latest('id')->get(),
            'endpoints' => WebhookEndpoint::query()->withCount(['deliveries as failed_count' => fn ($query) => $query->where('status', 'failed')->where('created_at', '>=', now()->subDay())])->latest('id')->get(),
            'events' => Webhooks::EVENTS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'access' => ['required', Rule::in(array_keys(ApiToken::ACCESS))],
            'expires_in' => ['nullable', Rule::in(array_filter(array_keys(ApiToken::EXPIRY_DAYS)))],
        ]);

        $workspaceId = $this->context->id();
        if (ApiToken::where('workspace_id', $workspaceId)->count() >= self::MAX_KEYS) {
            return back()->withErrors(['name' => 'This workspace already has '.self::MAX_KEYS.' keys. Revoke one you no longer use first.']);
        }

        $newToken = $request->user()->createToken(
            $data['name'], ApiToken::ACCESS[$data['access']]['abilities'],
            empty($data['expires_in']) ? null : now()->addDays((int) $data['expires_in']),
        );
        $newToken->accessToken->forceFill(['workspace_id' => $workspaceId])->save();

        Audit::log('settings', 'api-key-created', 'Created the API key "'.$data['name'].'" ('.ApiToken::ACCESS[$data['access']]['label'].')', $newToken->accessToken);

        return redirect()->route('settings.api.index')
            ->with('newApiKey', $newToken->plainTextToken)
            ->with('flash', ['type' => 'success', 'message' => 'Key created. Copy it now: it will not be shown again.']);
    }

    public function destroy(int $token): RedirectResponse
    {
        $apiToken = ApiToken::where('workspace_id', $this->context->id())->findOrFail($token);
        $apiToken->delete();

        Audit::log('settings', 'api-key-revoked', 'Revoked the API key "'.$apiToken->name.'"');

        return back()->with('flash', ['type' => 'success', 'message' => 'Key revoked. Apps using it stop working straight away.']);
    }
}
