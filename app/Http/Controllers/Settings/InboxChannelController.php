<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Inbox\Inbox;
use App\Models\InboxChannel;
use App\Sms\PhoneNumber;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Connect the email address, SMS number, WhatsApp number and social accounts the inbox collects from. */
class InboxChannelController extends Controller
{
    public function __construct(protected Inbox $inbox) {}

    public function index(): View
    {
        return view('settings.inbox', [
            'channels' => InboxChannel::query()->withCount('conversations')->orderBy('name')->get(),
            'drivers' => $this->inbox->drivers(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $channel = InboxChannel::create([
            'type' => $data['type'],
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'credentials' => $this->credentials($data['type'], $data['credentials'][$data['type']] ?? []),
            'test_mode' => $request->boolean('test_mode'),
            'is_active' => true,
        ]);

        Audit::log('settings', 'inbox-channel-added', "Added the {$channel->name} inbox channel", $channel);

        return redirect()->route('settings.inbox.index')->with('flash', ['type' => 'success', 'message' => "{$channel->name} added. Paste its webhook URL into {$channel->driver()->label()} to start receiving messages."]);
    }

    public function update(Request $request, InboxChannel $channel): RedirectResponse
    {
        $data = $this->validated($request, $channel);
        $channel->update([
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'credentials' => $this->credentials($channel->type, $data['credentials'][$channel->type] ?? [], $channel->credentials ?? []),
            'test_mode' => $request->boolean('test_mode'),
            'is_active' => $request->boolean('is_active'),
        ]);

        Audit::log('settings', 'inbox-channel-updated', "Updated the {$channel->name} inbox channel", $channel);

        return back()->with('flash', ['type' => 'success', 'message' => "{$channel->name} saved."]);
    }

    public function destroy(InboxChannel $channel): RedirectResponse
    {
        $channel->delete();
        Audit::log('settings', 'inbox-channel-removed', "Removed the {$channel->name} inbox channel and its conversations");

        return redirect()->route('settings.inbox.index')->with('flash', ['type' => 'success', 'message' => "{$channel->name} removed."]);
    }

    /** Pretend a customer wrote in, so the inbox can be tried before any provider is connected. */
    public function simulate(Request $request, InboxChannel $channel): RedirectResponse
    {
        abort_unless($channel->test_mode, 403);
        $data = $request->validate([
            'from' => ['required', 'string', 'max:190'],
            'name' => ['nullable', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:'.Inbox::MAX_LENGTH],
        ]);

        $handle = match ($channel->type) {
            'email' => filter_var(mb_strtolower(trim($data['from'])), FILTER_VALIDATE_EMAIL) ?: null,
            'sms', 'whatsapp' => PhoneNumber::normalize($data['from'], $channel->workspace?->country_code),
            default => trim($data['from']),
        };
        if (! $handle) {
            return back()->withInput()->withErrors(['from' => $channel->type === 'email' ? 'Enter an email address.' : 'Enter a valid phone number.']);
        }

        $message = $this->inbox->receive($channel, ['handle' => $handle, 'name' => $data['name'] ?? null, 'subject' => $channel->type === 'email' ? 'Test message' : null, 'body' => $data['body']]);
        abort_unless($message, 422);

        return redirect()->route('inbox.show', $message->inbox_conversation_id)->with('flash', ['type' => 'success', 'message' => 'Test message received.']);
    }

    /**
     * @return array{type: string, name: string, address?: ?string, credentials?: array<string, array<string, ?string>>}
     */
    protected function validated(Request $request, ?InboxChannel $channel = null): array
    {
        return $request->validate([
            'type' => [$channel ? 'prohibited' : 'required', Rule::in(array_keys($this->inbox->drivers()))],
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:190'],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'array'],
            'credentials.*.*' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * Keep only the driver's fields; a blank secret keeps the saved value.
     *
     * @param  array<string, ?string>  $values
     * @param  array<string, string>  $current
     * @return array<string, string>
     */
    protected function credentials(string $type, array $values, array $current = []): array
    {
        $credentials = [];
        foreach ($this->inbox->driver($type)->fields() as $field => $meta) {
            $value = trim((string) ($values[$field] ?? ''));
            $credentials[$field] = $meta['secret'] && $value === '' ? (string) ($current[$field] ?? '') : $value;
        }

        return $credentials;
    }
}
