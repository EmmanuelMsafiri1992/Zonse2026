<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Consultancy with client portals: deliverables are only added to proposals and active engagements. A
 * deliverable is sent to the client with a file link, and a rejection needs the client's feedback; it then
 * goes back for rework. An engagement can't be completed until every deliverable is approved.
 */
class ConsultancyLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'engagements') {
            if ($payload['status'] === 'completed' && $existing && $existing->status !== 'completed' && ($waiting = $this->unapproved($existing)) > 0) {
                $errors['status'] = $existing->title.' still has '.$waiting.' '.str('deliverable')->plural($waiting).' not approved.';
            }
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The end date can\'t be before the start date.';
            }

            return $errors;
        }
        if (! $existing && filled($data['engagement'] ?? null) && ($engagement = $this->records('engagements')->find($data['engagement'])) && ! in_array($engagement->status, ['proposal', 'active'], true)) {
            $errors['data.engagement'] = $engagement->title.' is '.str_replace('_', ' ', $engagement->status).'.';
        }
        if (in_array($payload['status'], ['with_client', 'approved'], true) && blank($data['file_url'] ?? null)) {
            $errors['data.file_url'] = 'Add the file link before sending it to the client.';
        }
        if ($payload['status'] === 'rejected' && blank($data['client_feedback'] ?? null)) {
            $errors['data.client_feedback'] = 'Record the client\'s feedback.';
        }

        return $errors;
    }

    /**
     * How many of an engagement's deliverables are not approved yet.
     */
    protected function unapproved(Record $engagement): int
    {
        return $this->linked('deliverables', 'engagement', $engagement)->where('status', '!=', 'approved')->count();
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'engagements') {
            return match ($record->status) {
                'proposal' => ['start' => ['label' => 'Start engagement', 'icon' => 'play']],
                'active' => ['complete' => ['label' => 'Complete', 'icon' => 'flag']],
                default => [],
            };
        }

        return match ($record->status) {
            'not_started', 'rejected' => ['work' => ['label' => $record->status === 'rejected' ? 'Rework' : 'Start', 'icon' => 'pencil']],
            'in_progress' => ['send' => ['label' => 'Send to client', 'icon' => 'send', 'fields' => [['name' => 'file_url', 'label' => 'File link', 'type' => 'url', 'value' => $record->value('file_url')]]]],
            'with_client' => [
                'approve' => ['label' => 'Client approved', 'icon' => 'check'],
                'reject' => ['label' => 'Client rejected', 'icon' => 'x', 'fields' => [['name' => 'client_feedback', 'label' => 'Client feedback', 'type' => 'textarea', 'value' => '']]],
            ],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'start':
                $record->update(['status' => 'active', 'occurs_on' => $record->occurs_on ?? today()]);

                return $record->title.' is under way.';
            case 'complete':
                if (($waiting = $this->unapproved($record)) > 0) {
                    throw ValidationException::withMessages(['status' => $record->title.' still has '.$waiting.' '.str('deliverable')->plural($waiting).' not approved.']);
                }
                $record->update(['status' => 'completed']);

                return $record->title.' completed.';
            case 'work':
                $record->update(['status' => 'in_progress']);

                return $record->title.' in progress.';
            case 'send':
                $link = $request->validate(['file_url' => ['required', 'url']], ['file_url.required' => 'Add the file link before sending it to the client.'])['file_url'];
                $record->update(['status' => 'with_client', 'data' => [...$record->data, 'file_url' => $link, '_sent_on' => today()->toDateString()]]);

                return $record->title.' sent to the client.';
            case 'approve':
                $record->update(['status' => 'approved']);
                $engagement = $this->parent($record, 'engagement');
                $left = $engagement ? $this->unapproved($engagement) : 0;

                return $record->title.' approved'.($engagement ? '; '.($left ? $left.' still to approve on '.$engagement->title : 'every deliverable on '.$engagement->title.' is approved') : '').'.';
            default:
                $feedback = trim((string) ($request->validate(['client_feedback' => ['required', 'string']], ['client_feedback.required' => 'Record the client\'s feedback.'])['client_feedback']));
                $record->update(['status' => 'rejected', 'data' => [...$record->data, 'client_feedback' => $feedback]]);

                return $record->title.' rejected by the client.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'engagements') {
            return [];
        }
        $deliverables = $this->linked('deliverables', 'engagement', $record)->orderBy('due_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Deliverables: '.$deliverables->where('status', 'approved')->count().' of '.$deliverables->count().' approved', 'icon' => 'file-check', 'empty' => 'No deliverables yet.',
            'rows' => $deliverables->map(fn (Record $item) => [
                'label' => $item->title, 'sub' => str_replace('_', ' ', $item->status), 'value' => $item->due_on?->format('d M') ?? '—', 'href' => $item->url(),
                'tone' => match (true) {
                    $item->status === 'approved' => 'success',
                    $item->status === 'rejected', $item->due_on && $item->due_on->lt(today()) => 'danger',
                    default => null,
                },
            ])->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $engagements = $this->records('engagements')->get()->keyBy('id');
        $open = $this->records('deliverables')->where('status', '!=', 'approved')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Waiting on clients', 'icon' => 'hourglass', 'empty' => 'Nothing with clients.',
                'rows' => $open->where('status', 'with_client')->sortBy(fn (Record $item) => (string) $item->value('_sent_on'))
                    ->map(fn (Record $item) => ['label' => $item->title, 'sub' => $engagements->get((int) $item->value('engagement'))?->title ?? '', 'value' => filled($item->value('_sent_on')) ? 'Sent '.Carbon::parse($item->value('_sent_on'))->format('d M') : '', 'href' => $item->url()])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Overdue deliverables', 'icon' => 'alarm-clock', 'empty' => 'Nothing overdue.',
                'rows' => $open->where('status', '!=', 'with_client')->filter(fn (Record $item) => $item->due_on && $item->due_on->lt(today()))->sortBy('due_on')
                    ->map(fn (Record $item) => ['label' => $item->title, 'sub' => $engagements->get((int) $item->value('engagement'))?->title ?? '', 'value' => 'Due '.$item->due_on->format('d M'), 'href' => $item->url(), 'tone' => 'danger'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $deliverables = $this->records('deliverables')->get()->groupBy(fn (Record $item) => (int) $item->value('engagement'));

        return [['title' => 'Engagements', 'columns' => ['Engagement', 'Status', 'Billing', 'Fee', 'Deliverables', 'Approved', 'Rejections'], 'rows' => $this->dated('engagements', $from, $to)->orderBy('occurs_on')->get()
            ->map(function (Record $engagement) use ($deliverables) {
                $items = $deliverables->get($engagement->id, collect());

                return [
                    $engagement->title, ucfirst(str_replace('_', ' ', $engagement->status)), ucfirst(str_replace('_', ' ', (string) $engagement->value('billing'))), $this->money($engagement->amount),
                    $items->count(), $items->where('status', 'approved')->count(), $items->where('status', 'rejected')->count(),
                ];
            })->all()]];
    }
}
