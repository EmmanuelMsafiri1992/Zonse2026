<?php

namespace App\Support;

use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Models\Service;
use Modules\Appointments\Support\BookingSettings;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Item;
use Modules\Invoicing\Payments\PaymentGateways;

/**
 * The workspace's public "link in bio" page: its settings, which blocks (booking, ordering,
 * paying an amount) can show, the free booking times on a day, and the contact a visitor becomes.
 */
class PublicPage
{
    /** Blocks the page can show, with the module each one needs. */
    public const BLOCKS = [
        'booking' => ['label' => 'Book an appointment', 'icon' => 'calendar-check', 'module' => 'appointments', 'route' => 'public.booking'],
        'ordering' => ['label' => 'Order online', 'icon' => 'shopping-bag', 'module' => 'invoicing', 'route' => 'public.order'],
        'payment' => ['label' => 'Make a payment', 'icon' => 'credit-card', 'module' => 'invoicing', 'route' => 'public.payment'],
    ];

    public const MAX_LINKS = 8;

    public const DEFAULTS = [
        'enabled' => false,
        'headline' => null,
        'bio' => null,
        'links' => [],
        'blocks' => ['booking', 'ordering', 'payment'],
        'min_notice_hours' => 2,
        'max_days_ahead' => 30,
        'capacity' => 1,
    ];

    public function __construct(protected PaymentGateways $gateways) {}

    /**
     * @return array{enabled: bool, headline: ?string, bio: ?string, links: list<array{label: string, url: string}>, blocks: list<string>, min_notice_hours: int, max_days_ahead: int, capacity: int}
     */
    public function settings(Workspace $workspace): array
    {
        $links = $workspace->setting('public_page.links', []);
        $blocks = $workspace->setting('public_page.blocks', self::DEFAULTS['blocks']);

        return [
            'enabled' => (bool) $workspace->setting('public_page.enabled', false),
            'headline' => $workspace->setting('public_page.headline') ?: null,
            'bio' => $workspace->setting('public_page.bio') ?: null,
            'links' => array_values(array_filter(is_array($links) ? $links : [], fn ($link) => is_array($link) && ! empty($link['label']) && ! empty($link['url']))),
            'blocks' => array_values(array_intersect(is_array($blocks) ? $blocks : [], array_keys(self::BLOCKS))),
            'min_notice_hours' => (int) $workspace->setting('public_page.min_notice_hours', self::DEFAULTS['min_notice_hours']),
            'max_days_ahead' => max(1, (int) $workspace->setting('public_page.max_days_ahead', self::DEFAULTS['max_days_ahead'])),
            'capacity' => max(1, (int) $workspace->setting('public_page.capacity', self::DEFAULTS['capacity'])),
        ];
    }

    /** @param  array<string, mixed>  $values */
    public function save(Workspace $workspace, array $values): void
    {
        $settings = $workspace->settings ?? [];
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            data_set($settings, 'public_page.'.$key, $value);
        }
        $workspace->forceFill(['settings' => $settings])->save();
    }

    /**
     * Blocks the workspace could offer, whatever the owner chose: the app must be on and have
     * something to offer (bookable services, items for sale, a payment method for paying online).
     *
     * @return array<string, array{label: string, icon: string, module: string, route: string}>
     */
    public function availableBlocks(Workspace $workspace): array
    {
        return array_filter(self::BLOCKS, fn (array $block, string $key) => $workspace->hasModule($block['module']) && match ($key) {
            'booking' => Service::forWorkspace($workspace)->active()->exists(),
            'ordering' => $this->orderableItems($workspace)->isNotEmpty(),
            'payment' => $this->gateways->enabledFor($workspace) !== [],
            default => false,
        }, ARRAY_FILTER_USE_BOTH);
    }

    /** @return array<string, array{label: string, icon: string, module: string, route: string}> */
    public function blocks(Workspace $workspace): array
    {
        return array_intersect_key($this->availableBlocks($workspace), array_flip($this->settings($workspace)['blocks']));
    }

    public function shows(Workspace $workspace, string $block): bool
    {
        return $this->settings($workspace)['enabled'] && array_key_exists($block, $this->blocks($workspace));
    }

    /**
     * Items for sale on the page: active, priced, and in stock when stock is tracked.
     *
     * @return Collection<int, Item>
     */
    public function orderableItems(Workspace $workspace): Collection
    {
        return Item::forWorkspace($workspace)->active()->with('taxRate')->where('price', '>', 0)->orderBy('name')->get()
            ->reject(fn (Item $item) => $item->tracksStock() && (float) $item->stock_qty <= 0)
            ->values();
    }

    /** The first and last day a visitor may book, in the workspace's own time. */
    public function bookingWindow(Workspace $workspace): array
    {
        $settings = $this->settings($workspace);
        $earliest = $this->now($workspace)->addHours($settings['min_notice_hours']);

        return [$earliest->startOfDay(), $this->now($workspace)->addDays($settings['max_days_ahead'])->startOfDay()];
    }

    /**
     * Start times still free on a day for a service: inside opening hours, after the notice period,
     * and with fewer overlapping bookings than the page's capacity.
     *
     * @return list<string> times as H:i
     */
    public function slots(Workspace $workspace, Service $service, CarbonImmutable $day): array
    {
        $booking = BookingSettings::for($workspace);
        $settings = $this->settings($workspace);
        [$first, $last] = $this->bookingWindow($workspace);
        $day = $day->startOfDay();

        if ($day->lt($first) || $day->gt($last) || ! in_array($day->dayOfWeekIso, $booking['working_days'], true)) {
            return [];
        }

        $earliest = $this->now($workspace)->addHours($settings['min_notice_hours']);
        $closes = $day->setTimeFromTimeString($booking['day_end']);
        $step = max(5, $booking['slot_minutes']);
        $duration = max(5, (int) $service->duration_minutes);

        $taken = Appointment::forWorkspace($workspace)->active()->onDay($day)->get(['starts_at', 'ends_at']);

        $slots = [];
        for ($start = $day->setTimeFromTimeString($booking['day_start']); $start->addMinutes($duration)->lte($closes); $start = $start->addMinutes($step)) {
            if ($start->lt($earliest)) {
                continue;
            }
            $end = $start->addMinutes($duration);
            $overlapping = $taken->filter(fn (Appointment $a) => $a->starts_at->format('Y-m-d H:i:s') < $end->format('Y-m-d H:i:s')
                && $a->ends_at->format('Y-m-d H:i:s') > $start->format('Y-m-d H:i:s'))->count();
            if ($overlapping < $settings['capacity']) {
                $slots[] = $start->format('H:i');
            }
        }

        return $slots;
    }

    /** Wall-clock "now" in the workspace's timezone, matching how appointment times are stored. */
    public function now(Workspace $workspace): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now($workspace->timezone ?: config('app.timezone'))->format('Y-m-d H:i:s'));
    }

    /**
     * The contact a visitor becomes: an existing one with the same email, or a new customer.
     *
     * @param  array{name: string, email: string, phone?: ?string}  $visitor
     */
    public function contactFor(Workspace $workspace, array $visitor): Contact
    {
        $email = mb_strtolower(trim($visitor['email']));
        $contact = Contact::forWorkspace($workspace)->whereRaw('lower(email) = ?', [$email])->orderBy('id')->first();

        if ($contact) {
            if (! $contact->phone && ! empty($visitor['phone'])) {
                $contact->forceFill(['phone' => $visitor['phone']])->save();
            }

            return $contact;
        }

        return Contact::create([
            'workspace_id' => $workspace->id,
            'type' => 'customer',
            'kind' => 'person',
            'name' => trim($visitor['name']),
            'email' => $email,
            'phone' => $visitor['phone'] ?? null,
            'currency_code' => $workspace->currency_code,
            'tags' => ['online'],
            'is_active' => true,
        ]);
    }
}
