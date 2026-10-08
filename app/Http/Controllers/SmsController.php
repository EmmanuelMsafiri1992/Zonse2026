<?php

namespace App\Http\Controllers;

use App\Models\SmsMessage;
use App\Sms\SmsService;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Contacts\Models\Contact;

/** Message history plus a compose form for one number or a whole group of contacts. */
class SmsController extends Controller
{
    /** Most recipients one bulk send may reach, so a mistake cannot run up a large bill. */
    public const MAX_RECIPIENTS = 500;

    public const AUDIENCES = [
        'number' => 'One phone number',
        'customers' => 'All customers',
        'suppliers' => 'All suppliers',
        'leads' => 'All leads',
        'contacts' => 'All contacts',
    ];

    public function __construct(protected WorkspaceContext $context, protected SmsService $sms) {}

    public function index(Request $request): View
    {
        $workspace = $this->context->getOrFail();
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(SmsMessage::STATUSES))],
            'purpose' => ['nullable', Rule::in(array_keys(SmsMessage::PURPOSES))],
        ]);

        $messages = SmsMessage::query()->with(['contact', 'sender'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['purpose'] ?? null, fn ($query, $purpose) => $query->where('purpose', $purpose))
            ->latest('id')->paginate(25)->withQueryString();

        $thisMonth = SmsMessage::query()->where('created_at', '>=', now()->startOfMonth());

        return view('sms.index', [
            'messages' => $messages,
            'filters' => $filters,
            'provider' => $this->sms->provider($workspace),
            'audiences' => $this->audiences(),
            'stats' => [
                'sent' => (clone $thisMonth)->where('status', 'sent')->count(),
                'segments' => (int) (clone $thisMonth)->where('status', 'sent')->sum('segments'),
                'failed' => (clone $thisMonth)->where('status', 'failed')->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->context->getOrFail();
        if (! $this->sms->enabled($workspace)) {
            return back()->with('flash', ['type' => 'danger', 'message' => 'Set up an SMS provider first.']);
        }

        $data = $request->validate([
            'audience' => ['required', Rule::in(array_keys($this->audiences()))],
            'to' => ['required_if:audience,number', 'nullable', 'string', 'max:30'],
            'body' => ['required', 'string', 'max:'.SmsService::MAX_LENGTH],
        ]);

        if ($data['audience'] === 'number') {
            $message = $this->sms->send($workspace, (string) $data['to'], $data['body']);
            if (! $message) {
                return back()->withInput()->withErrors(['to' => 'Enter a valid mobile number, e.g. 0771234567 or +263771234567.']);
            }

            return back()->with('flash', ['type' => 'success', 'message' => 'Message to '.$message->to.' is on its way.']);
        }

        $numbers = $this->audienceNumbers($data['audience']);
        if ($numbers === []) {
            return back()->withInput()->with('flash', ['type' => 'warning', 'message' => 'None of those contacts has a usable phone number.']);
        }
        if (count($numbers) > self::MAX_RECIPIENTS) {
            return back()->withInput()->with('flash', ['type' => 'danger', 'message' => 'That group has '.count($numbers).' numbers. One send can reach at most '.self::MAX_RECIPIENTS.'.']);
        }

        $batch = (string) Str::uuid();
        DB::transaction(function () use ($numbers, $workspace, $data, $batch) {
            foreach ($numbers as $number => $contact) {
                $this->sms->send($workspace, $number, $data['body'], ['purpose' => 'bulk', 'contact' => $contact, 'batch' => $batch, 'country' => null]);
            }
        });

        return redirect()->route('sms.index')->with('flash', ['type' => 'success', 'message' => count($numbers).' '.Str::plural('message', count($numbers)).' queued.']);
    }

    /** @return array<string, string> */
    protected function audiences(): array
    {
        return $this->context->hasModule('contacts') ? self::AUDIENCES : ['number' => self::AUDIENCES['number']];
    }

    /**
     * One entry per distinct number, so a number shared by two contacts is texted once.
     *
     * @return array<string, Contact>
     */
    protected function audienceNumbers(string $audience): array
    {
        $workspace = $this->context->getOrFail();
        $type = ['customers' => 'customer', 'suppliers' => 'supplier', 'leads' => 'lead'][$audience] ?? null;

        $numbers = [];
        Contact::query()
            ->when($type, fn ($query) => $query->where('type', $type))
            ->where(fn ($query) => $query->whereNotNull('mobile')->where('mobile', '!=', '')->orWhere(fn ($query) => $query->whereNotNull('phone')->where('phone', '!=', '')))
            ->orderBy('id')
            ->each(function (Contact $contact) use (&$numbers, $workspace) {
                $number = $this->sms->numberFor($contact, $workspace);
                if ($number && ! isset($numbers[$number])) {
                    $numbers[$number] = $contact;
                }
            });

        return $numbers;
    }
}
