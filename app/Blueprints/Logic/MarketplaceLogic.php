<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Multi-vendor marketplace: a vendor's commission is between 0 and 100%, and only approved vendors can
 * have live listings. A listing with no stock is sold out and goes live again when restocked. Suspending
 * a vendor takes their live listings back to review. A payout works out the commission from the vendor's
 * rate and pays the rest, once per vendor per period, and only to a vendor with bank details.
 */
class MarketplaceLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'vendors') {
            $rate = (float) ($data['commission_percent'] ?? 0);
            if ($rate < 0 || $rate > 100) {
                $errors['data.commission_percent'] = 'Commission must be between 0 and 100%.';
            }
        }
        if ($entity->key === 'listings' && $payload['status'] === 'live' && filled($data['vendor'] ?? null) && ($vendor = $this->records('vendors')->find($data['vendor'])) && $vendor->status !== 'approved') {
            $errors['status'] = $vendor->title.' is '.$vendor->status.', so its listings cannot go live.';
        }
        if ($entity->key === 'payouts' && filled($data['vendor'] ?? null) && filled($payload['title'] ?? null)) {
            $twin = $this->linked('payouts', 'vendor', (int) $data['vendor'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $payout) => mb_strtolower(trim($payout->title)) === mb_strtolower(trim($payload['title'])));
            if ($twin) {
                $errors['title'] = 'This vendor already has a payout for '.$twin->title.'.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'listings') {
            $stock = $this->number($record, 'stock');
            if ($record->status === 'live' && $stock <= 0) {
                $record->status = 'sold_out';
            } elseif ($record->status === 'sold_out' && $stock > 0) {
                $record->status = 'live';
            }
        }
        if ($record->entity === 'payouts') {
            $gross = $this->number($record, 'gross_sales');
            if ($this->number($record, 'commission') <= 0 && ($vendor = $this->parent($record, 'vendor'))) {
                $this->put($record, ['commission' => round($gross * $this->number($vendor, 'commission_percent') / 100, 2)]);
            }
            $record->amount = round(max(0, $gross - $this->number($record, 'commission')), 2);
        }
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'vendors' && $record->status === 'applied' => ['approve' => ['label' => 'Approve', 'icon' => 'check']],
            $record->entity === 'vendors' && $record->status === 'approved' => ['suspend' => ['label' => 'Suspend', 'icon' => 'pause']],
            $record->entity === 'vendors' && $record->status === 'suspended' => ['approve' => ['label' => 'Reinstate', 'icon' => 'play']],
            $record->entity === 'listings' && $record->status === 'pending_review' => ['go_live' => ['label' => 'Approve listing', 'icon' => 'check'], 'reject' => ['label' => 'Reject', 'icon' => 'x']],
            $record->entity === 'payouts' && $record->status === 'calculated' => ['approve_payout' => ['label' => 'Approve', 'icon' => 'check']],
            $record->entity === 'payouts' && $record->status === 'approved' => ['pay' => ['label' => 'Paid', 'icon' => 'banknote']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'approve':
                $record->update(['status' => 'approved']);

                return $record->title.' is approved.';
            case 'suspend':
                $live = $this->linked('listings', 'vendor', $record)->whereIn('status', ['live', 'sold_out'])->get()->each(fn (Record $listing) => $listing->update(['status' => 'pending_review']));
                $record->update(['status' => 'suspended']);

                return $record->title.' suspended; '.$live->count().' '.str('listing')->plural($live->count()).' taken down for review.';
            case 'go_live':
                $vendor = $this->parent($record, 'vendor');
                if ($vendor && $vendor->status !== 'approved') {
                    throw ValidationException::withMessages(['status' => $vendor->title.' is '.$vendor->status.', so its listings cannot go live.']);
                }
                $record->update(['status' => 'live']);

                return $record->title.' is '.str_replace('_', ' ', $record->status).'.';
            case 'reject':
                $record->update(['status' => 'rejected']);

                return $record->title.' rejected.';
            case 'approve_payout':
                $record->update(['status' => 'approved']);

                return 'Payout of '.$this->money($record->amount).' approved.';
            default:
                $vendor = $this->parent($record, 'vendor');
                if (! $vendor || blank($vendor->value('bank_details'))) {
                    throw ValidationException::withMessages(['status' => ($vendor?->title ?? 'The vendor').' has no payout bank details.']);
                }
                $record->update(['status' => 'paid', 'occurs_on' => today()]);

                return 'Paid '.$this->money($record->amount).' to '.$vendor->title.'.';
        }
    }

    public function homeCards(): array
    {
        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Marketplace', 'icon' => 'store', 'stats' => [
            ['label' => 'Vendors to approve', 'value' => $this->records('vendors')->where('status', 'applied')->count()],
            ['label' => 'Listings to review', 'value' => $this->records('listings')->where('status', 'pending_review')->count()],
            ['label' => 'Live listings', 'value' => $this->records('listings')->where('status', 'live')->count()],
            ['label' => 'Payouts due', 'value' => $this->money($this->records('payouts')->whereIn('status', ['calculated', 'approved'])->sum('amount'))],
        ]]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $payouts = $this->records('payouts')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get();
        $vendors = $this->records('vendors')->pluck('title', 'id');

        return [['title' => 'Sales and commission by vendor', 'columns' => ['Vendor', 'Gross sales', 'Commission', 'Paid out', 'Still to pay'], 'rows' => $payouts
            ->groupBy(fn (Record $payout) => (string) ($vendors[(int) $payout->value('vendor')] ?? 'Unknown'))->sortKeys()
            ->map(fn ($group, string $vendor) => [$vendor, $this->money($group->sum(fn (Record $payout) => $this->number($payout, 'gross_sales'))), $this->money($group->sum(fn (Record $payout) => $this->number($payout, 'commission'))),
                $this->money($group->where('status', 'paid')->sum('amount')), $this->money($group->where('status', '!=', 'paid')->sum('amount'))])
            ->values()->all()]];
    }
}
