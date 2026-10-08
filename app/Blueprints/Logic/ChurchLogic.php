<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Models\Record;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Church: each member's giving adds up on their page and prints as an annual statement,
 * birthdays come up on the home page, and reports break giving and attendance down.
 */
class ChurchLogic extends AppLogic
{
    public const GIVING_TYPES = ['tithe' => 'Tithe', 'offering' => 'Offering', 'pledge' => 'Pledge', 'building_fund' => 'Building fund', 'mission' => 'Mission', 'other' => 'Other'];

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'members') {
            return [];
        }

        $gifts = $this->linked('giving', 'member', $record)->get();
        $year = today()->year;
        $inYear = fn (int $y) => $gifts->filter(fn (Record $gift) => $this->giftDate($gift)->year === $y)->sum('amount');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Giving', 'icon' => 'hand-coins', 'stats' => [
                ['label' => 'This year', 'value' => $this->money($inYear($year))],
                ['label' => 'Last year', 'value' => $this->money($inYear($year - 1))],
                ['label' => 'All time', 'value' => $this->money($gifts->sum('amount'))],
                ['label' => 'Gifts', 'value' => (string) $gifts->count()],
            ], 'note' => 'Print the annual statement from the button at the top of the page.']],
        ];
    }

    public function documents(Record $record): array
    {
        return $record->entity === 'members' ? ['statement' => 'Giving statement'] : [];
    }

    public function document(string $name, Record $record): ?array
    {
        if ($name !== 'statement' || $record->entity !== 'members') {
            return null;
        }

        $year = (int) request()->query('year', (string) today()->year);
        $gifts = $this->linked('giving', 'member', $record)->get()
            ->filter(fn (Record $gift) => $this->giftDate($gift)->year === $year)
            ->sortBy(fn (Record $gift) => $this->giftDate($gift)->timestamp);

        $byType = $gifts->groupBy(fn (Record $gift) => self::GIVING_TYPES[$gift->value('type')] ?? 'Other')
            ->map(fn (Collection $group) => $this->money($group->sum('amount')))->all();

        return ['view' => 'apps.logic.document', 'data' => [
            'heading' => 'Giving statement',
            'meta' => ['Member' => $record->title, 'Year' => (string) $year, 'Gifts' => (string) $gifts->count()],
            'columns' => ['Date', 'Receipt', 'Type', 'Amount'],
            'rows' => $gifts->map(fn (Record $gift) => [
                $this->giftDate($gift)->format('d M Y'), $gift->number, self::GIVING_TYPES[$gift->value('type')] ?? '—', $this->money($gift->amount),
            ])->values()->all(),
            'totals' => [...$byType, 'Total for '.$year => $this->money($gifts->sum('amount'))],
            'notes' => 'Thank you for your faithful giving. Please keep this statement for your records.',
        ]];
    }

    public function homeCards(): array
    {
        $month = today()->month;
        $birthdays = $this->records('members')->whereNot('status', 'inactive')->whereNotNull('data->date_of_birth')->get()
            ->filter(fn (Record $member) => Carbon::parse($member->value('date_of_birth'))->month === $month)
            ->sortBy(fn (Record $member) => Carbon::parse($member->value('date_of_birth'))->day)->take(15);

        $gifts = $this->dated('giving', today()->startOfMonth(), today()->endOfDay())->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Birthdays in '.today()->format('F'), 'icon' => 'cake', 'empty' => 'No birthdays this month.',
                'rows' => $birthdays->map(fn (Record $member) => [
                    'label' => $member->title, 'sub' => $member->value('phone') ?: $member->number,
                    'value' => Carbon::parse($member->value('date_of_birth'))->format('d M'), 'href' => $member->url(),
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Giving this month', 'icon' => 'hand-coins', 'stats' => [
                ['label' => 'Total', 'value' => $this->money($gifts->sum('amount'))],
                ['label' => 'Tithes', 'value' => $this->money($gifts->filter(fn ($gift) => $gift->value('type') === 'tithe')->sum('amount'))],
                ['label' => 'Offerings', 'value' => $this->money($gifts->filter(fn ($gift) => $gift->value('type') === 'offering')->sum('amount'))],
                ['label' => 'Givers', 'value' => (string) $gifts->map(fn ($gift) => $gift->value('member') ?: $gift->title)->unique()->count()],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $gifts = $this->dated('giving', $from, $to)->get();
        $total = $gifts->sum('amount');
        $share = fn (float $amount) => $total > 0 ? round($amount / $total * 100).'%' : '—';

        $byType = $gifts->groupBy(fn (Record $gift) => self::GIVING_TYPES[$gift->value('type')] ?? 'Other')
            ->map(fn ($group, $type) => [$type, $group->count(), $this->money($group->sum('amount')), $share($group->sum('amount'))])->sortByDesc(1)->values()->all();
        $byMethod = $gifts->groupBy(fn (Record $gift) => ucfirst(str_replace('_', ' ', (string) ($gift->value('method') ?: 'not recorded'))))
            ->map(fn ($group, $method) => [$method, $group->count(), $this->money($group->sum('amount')), $share($group->sum('amount'))])->sortByDesc(1)->values()->all();

        $monthly = [];
        $byMonth = $this->sumByMonth($gifts);
        foreach ($this->months($from, $to) as $key => $label) {
            if (isset($byMonth[$key])) {
                $monthly[] = [$label, $this->money($byMonth[$key])];
            }
        }

        $attendance = $this->dated('services', $from, $to)->where('status', 'held')->orderBy('occurs_on')->get()
            ->map(fn (Record $service) => [($service->occurs_on ?? $service->created_at)->format('d M Y').' · '.$service->title, (string) ($service->value('preacher') ?: '—'), (int) $service->value('attendance')])->all();

        return [
            ['title' => 'Giving by type', 'columns' => ['Type', 'Gifts', 'Amount', 'Share'], 'rows' => $byType],
            ['title' => 'Giving by method', 'columns' => ['Method', 'Gifts', 'Amount', 'Share'], 'rows' => $byMethod],
            ['title' => 'Giving by month', 'columns' => ['Month', 'Amount'], 'rows' => $monthly],
            ['title' => 'Attendance', 'columns' => ['Service', 'Preacher', 'Attendance'], 'rows' => $attendance,
                'note' => $attendance ? 'Average attendance: '.round(collect($attendance)->avg(2)) : null],
        ];
    }

    protected function giftDate(Record $gift): Carbon
    {
        return $gift->occurs_on ?? $gift->created_at;
    }
}
