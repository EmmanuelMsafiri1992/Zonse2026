<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Classifieds & directory listings: each package has a fee and a run (free 14 days, standard and featured
 * 30, premium 60). A listing needs a proper description and a phone or website. Approving a listing puts
 * it live from today until its run ends; featured and premium listings show as featured. Renewing adds
 * another run and its fee. Live listings past their date expire each night.
 */
class ClassifiedsLogic extends AppLogic
{
    /**
     * Fee and days live for each package.
     *
     * @var array<string, array{fee: int, days: int}>
     */
    public const PACKAGES = [
        'free' => ['fee' => 0, 'days' => 14],
        'standard' => ['fee' => 50, 'days' => 30],
        'featured' => ['fee' => 150, 'days' => 30],
        'premium' => ['fee' => 300, 'days' => 60],
    ];

    /**
     * Statuses a listing is shown under.
     *
     * @var list<string>
     */
    public const LIVE = ['live', 'featured'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if (mb_strlen(trim((string) ($data['description'] ?? ''))) < 20) {
            $errors['data.description'] = 'Describe the listing in at least 20 characters.';
        }
        if (blank($data['phone'] ?? null) && blank($data['website'] ?? null)) {
            $errors['data.phone'] = 'Give a phone number or website so people can reach the advertiser.';
        }

        return $errors;
    }

    /**
     * The package a listing is on.
     *
     * @return array{fee: int, days: int}
     */
    protected function package(Record $listing): array
    {
        return self::PACKAGES[$listing->value('package')] ?? self::PACKAGES['free'];
    }

    public function saving(Record $record): void
    {
        if (! isset(self::PACKAGES[$record->value('package')])) {
            $this->put($record, ['package' => 'free']);
        }
        if ((float) $record->amount <= 0) {
            $record->amount = $this->package($record)['fee'];
        }
        if (! in_array($record->status, self::LIVE, true)) {
            return;
        }
        $record->occurs_on ??= today();
        $record->due_on ??= $record->occurs_on->copy()->addDays($this->package($record)['days']);
        $record->status = match (true) {
            $record->due_on->lt(today()) => 'expired',
            in_array($record->value('package'), ['featured', 'premium'], true) => 'featured',
            default => 'live',
        };
    }

    public function daily(Workspace $workspace): int
    {
        return $this->records('listings')->whereIn('status', self::LIVE)->whereDate('due_on', '<', today()->toDateString())->get()
            ->each(fn (Record $listing) => $listing->save())->count();
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->status === 'pending' => ['approve' => ['label' => 'Approve', 'icon' => 'check'], 'reject' => ['label' => 'Reject', 'icon' => 'x']],
            in_array($record->status, [...self::LIVE, 'expired'], true) => ['renew' => ['label' => 'Renew', 'icon' => 'refresh-cw']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'approve':
                $record->update(['status' => 'live', 'occurs_on' => today(), 'due_on' => null]);

                return $record->title.' is '.$record->status.' until '.$record->due_on->format('d M Y').'.';
            case 'reject':
                $record->update(['status' => 'rejected']);

                return $record->title.' rejected.';
            default:
                $package = $this->package($record);
                $from = $record->due_on && $record->due_on->gte(today()) ? $record->due_on : today();
                $record->update(['status' => 'live', 'occurs_on' => $record->occurs_on ?? today(), 'due_on' => $from->copy()->addDays($package['days']), 'amount' => (float) $record->amount + $package['fee']]);

                return $record->title.' renewed until '.$record->due_on->format('d M Y').($package['fee'] > 0 ? '; renewal fee '.$this->money($package['fee']) : '').'.';
        }
    }

    public function homeCards(): array
    {
        $pending = $this->records('listings')->where('status', 'pending')->oldest()->get();
        $live = $this->records('listings')->whereIn('status', self::LIVE)->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Listings', 'icon' => 'newspaper', 'stats' => [
                ['label' => 'Waiting for moderation', 'value' => $pending->count()],
                ['label' => 'Live', 'value' => $live->count()],
                ['label' => 'Expiring in 7 days', 'value' => $live->filter(fn (Record $listing) => $listing->due_on && $listing->due_on->lte(today()->addDays(7)))->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'To moderate', 'icon' => 'shield-check', 'empty' => 'Nothing waiting for moderation.',
                'rows' => $pending->map(fn (Record $listing) => ['label' => $listing->title, 'sub' => ucfirst(str_replace('_', ' ', (string) $listing->value('category'))), 'value' => ucfirst((string) $listing->value('package')), 'href' => $listing->url()])->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        return [['title' => 'Listings by category', 'columns' => ['Category', 'Listings', 'Live now', 'Fees'], 'rows' => $this->dated('listings', $from, $to)->get()
            ->groupBy(fn (Record $listing) => ucfirst(str_replace('_', ' ', (string) $listing->value('category'))))->sortKeys()
            ->map(fn ($group, string $category) => [$category, $group->count(), $group->whereIn('status', self::LIVE)->count(), $this->money($group->where('status', '!=', 'rejected')->sum('amount'))])
            ->values()->all()]];
    }
}
