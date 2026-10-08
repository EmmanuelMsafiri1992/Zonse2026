<?php

namespace Database\Seeders;

use App\Blueprints\BlueprintRegistry;
use App\Models\Module;
use App\Models\Suite;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Seeds suites and the full module catalogue from docs/01-MODULE-CATALOGUE.md,
 * so the markdown stays the single source of truth. Keys for the modules
 * that get built first are pinned in $keys so code can rely on them.
 */
class CatalogueSeeder extends Seeder
{
    /** suite number => [key, name, icon] */
    protected array $suites = [
        0 => ['core', 'Platform core', 'layers'],
        1 => ['finance', 'Finance & accounting', 'calculator'],
        2 => ['sales', 'Sales, CRM & customer service', 'handshake'],
        3 => ['marketing', 'Marketing & content', 'megaphone'],
        4 => ['operations', 'Operations, inventory & supply chain', 'boxes'],
        5 => ['hr', 'HR & people', 'users'],
        6 => ['projects', 'Projects, tasks & collaboration', 'kanban-square'],
        7 => ['healthcare', 'Healthcare', 'stethoscope'],
        8 => ['education', 'Education', 'graduation-cap'],
        9 => ['hospitality', 'Hospitality, food & travel', 'utensils'],
        10 => ['property', 'Real estate, property & facilities', 'home'],
        11 => ['construction', 'Construction, engineering & trades', 'hard-hat'],
        12 => ['events', 'Events, ticketing & community', 'ticket'],
        13 => ['services', 'Services & bookings', 'calendar-check'],
        14 => ['retail', 'Retail & e-commerce', 'shopping-cart'],
        15 => ['agriculture', 'Agriculture & agribusiness', 'tractor'],
        16 => ['transport', 'Transport & mobility', 'truck'],
        17 => ['utilities', 'Utilities, energy & telecom', 'zap'],
        18 => ['government', 'Government, public sector & compliance', 'landmark'],
        19 => ['industrial', 'Mining, manufacturing & industrial', 'factory'],
        20 => ['personal', 'Personal & lifestyle', 'heart'],
    ];

    /** ref => [key, name, icon, depends[]] — pinned so code & professions can reference them. */
    protected array $keys = [
        '1.1' => ['accounting', 'Accounting', 'book-open', []],
        '1.2' => ['invoicing', 'Invoicing', 'file-text', []],
        '1.3' => ['quotes', 'Quotations & estimates', 'file-signature', ['invoicing']],
        '1.4' => ['banking', 'Banking & reconciliation', 'landmark', ['accounting']],
        '1.5' => ['payroll', 'Payroll', 'banknote', ['hr']],
        '1.6' => ['expenses', 'Expenses', 'receipt', []],
        '1.7' => ['petty-cash', 'Petty cash & cash books', 'wallet', []],
        '1.8' => ['budgeting', 'Budgeting & forecasting', 'trending-up', ['accounting']],
        '1.9' => ['tax', 'Tax & VAT', 'percent', ['accounting']],
        '1.10' => ['pos', 'Point of sale', 'monitor-smartphone', ['inventory']],
        '1.11' => ['purchasing', 'Purchasing & procurement', 'shopping-bag', ['inventory']],
        '1.12' => ['receivables', 'Receivables & credit control', 'hand-coins', ['invoicing']],
        '1.13' => ['payables', 'Payables & bills', 'file-minus', ['accounting']],
        '1.14' => ['fixed-assets', 'Fixed assets', 'building', ['accounting']],
        '1.15' => ['loans', 'Loans & microfinance', 'piggy-bank', []],
        '1.16' => ['savings-groups', 'Savings groups & SACCOs', 'users-round', []],
        '1.17' => ['recurring-billing', 'Subscription billing', 'repeat', ['invoicing']],
        '1.18' => ['payments', 'Payment gateways & wallet', 'credit-card', []],
        '1.19' => ['job-costing', 'Job costing & project accounting', 'pie-chart', ['accounting']],
        '1.25' => ['accounting-practice', 'Accounting practice management', 'briefcase', ['tasks']],
        '1.27' => ['insurance', 'Insurance management', 'shield', []],
        '2.1' => ['crm', 'CRM & sales pipeline', 'target', []],
        '2.2' => ['helpdesk', 'Helpdesk & support tickets', 'life-buoy', []],
        '2.3' => ['live-chat', 'Live chat & chatbot', 'message-circle', []],
        '2.4' => ['loyalty', 'Loyalty & rewards', 'gift', []],
        '2.5' => ['feedback', 'Feedback, surveys & reviews', 'star', []],
        '2.6' => ['contracts', 'Contracts & e-signature', 'pen-tool', []],
        '2.12' => ['customer-portal', 'Customer portal', 'globe', []],
        '3.1' => ['email-marketing', 'Email marketing', 'mail', []],
        '3.2' => ['sms-marketing', 'SMS & WhatsApp campaigns', 'message-square', []],
        '3.4' => ['website-builder', 'Website & landing pages', 'layout', []],
        '4.1' => ['inventory', 'Inventory & stock', 'package', []],
        '4.2' => ['warehouse', 'Warehouse management', 'warehouse', ['inventory']],
        '4.3' => ['suppliers', 'Suppliers & vendors', 'truck', []],
        '4.4' => ['manufacturing', 'Manufacturing & production', 'factory', ['inventory']],
        '4.5' => ['orders', 'Order management', 'clipboard-list', ['inventory']],
        '4.6' => ['delivery', 'Delivery & courier', 'map', []],
        '4.7' => ['fleet', 'Fleet & vehicles', 'car', []],
        '4.8' => ['maintenance', 'Equipment maintenance', 'wrench', []],
        '5.1' => ['hr', 'Employees (HR)', 'users', []],
        '5.2' => ['recruitment', 'Recruitment', 'user-search', []],
        '5.3' => ['leave', 'Leave management', 'calendar-off', ['hr']],
        '5.4' => ['attendance', 'Attendance & timesheets', 'clock', ['hr']],
        '5.5' => ['rosters', 'Shifts & rosters', 'calendar-days', ['hr']],
        '5.6' => ['performance', 'Performance & OKRs', 'award', ['hr']],
        '5.7' => ['training', 'Training & certifications', 'graduation-cap', ['hr']],
        '6.1' => ['tasks', 'Tasks', 'check-square', []],
        '6.2' => ['projects', 'Projects', 'kanban-square', ['tasks']],
        '6.3' => ['time-tracking', 'Time tracking', 'timer', []],
        '6.4' => ['documents', 'Documents & files', 'folder', []],
        '6.5' => ['team-chat', 'Team chat', 'messages-square', []],
        '6.7' => ['calendar', 'Shared calendars & rooms', 'calendar', []],
        '6.8' => ['wiki', 'Knowledge base & wiki', 'book', []],
        '6.12' => ['issues', 'Issue tracking & roadmap', 'bug', ['tasks']],
        '6.15' => ['freelancer', 'Freelancer workspace', 'laptop', ['invoicing', 'time-tracking']],
        '7.1' => ['clinic', 'Clinic & patients', 'stethoscope', ['appointments']],
        '7.2' => ['patient-appointments', 'Patient appointments & reminders', 'calendar-clock', ['appointments', 'clinic']],
        '7.3' => ['emr', 'Medical records (EMR)', 'clipboard-plus', ['clinic']],
        '7.4' => ['pharmacy', 'Pharmacy', 'pill', ['inventory']],
        '7.5' => ['laboratory', 'Laboratory', 'flask-conical', ['clinic']],
        '7.6' => ['hospital', 'Hospital & wards', 'hospital', ['clinic']],
        '7.7' => ['telemedicine', 'Telemedicine', 'video', ['clinic']],
        '7.8' => ['medical-claims', 'Medical aid & insurance claims', 'file-check', ['clinic']],
        '7.9' => ['specialist-practice', 'Dental, optometry & physio', 'smile', ['clinic']],
        '7.10' => ['veterinary', 'Veterinary clinic', 'paw-print', ['appointments']],
        '7.11' => ['patient-queue', 'Patient queue & tokens', 'list-ordered', ['clinic']],
        '7.22' => ['patient-portal', 'Patient portal', 'user-round', ['clinic']],
        '8.1' => ['school', 'School management', 'school', []],
        '8.2' => ['lms', 'Learning management (LMS)', 'monitor-play', []],
        '8.3' => ['exams', 'Exams & report cards', 'file-badge', ['school']],
        '8.4' => ['timetable', 'Timetabling', 'calendar-range', ['school']],
        '8.5' => ['library', 'Library', 'library', []],
        '8.6' => ['tutoring', 'Tutoring & lessons', 'presentation', ['appointments']],
        '8.7' => ['parent-portal', 'Parent & student portal', 'users-round', ['school']],
        '8.9' => ['university', 'University & college', 'graduation-cap', []],
        '8.10' => ['hostel', 'Hostel & boarding', 'bed', ['school']],
        '8.11' => ['school-transport', 'School transport', 'bus', ['school']],
        '8.14' => ['driving-school', 'Driving school', 'car-front', ['appointments']],
        '8.15' => ['daycare', 'Nursery & daycare', 'baby', []],
        '9.1' => ['hotel', 'Hotel & lodge', 'bed-double', []],
        '9.2' => ['restaurant', 'Restaurant & kitchen', 'utensils', ['pos']],
        '9.3' => ['food-delivery', 'Food ordering & delivery', 'bike', ['restaurant']],
        '9.4' => ['tours', 'Tours & travel', 'compass', []],
        '9.5' => ['car-rental', 'Car rental', 'key-round', []],
        '9.6' => ['bus-booking', 'Bus & coach seats', 'bus', []],
        '9.9' => ['venue-hire', 'Venues & banquets', 'party-popper', []],
        '10.1' => ['property-listings', 'Property listings & agents', 'building-2', []],
        '10.2' => ['tenants', 'Rentals & tenants', 'key', []],
        '10.3' => ['rent-collection', 'Rent collection', 'coins', ['tenants']],
        '10.4' => ['maintenance-requests', 'Maintenance requests', 'hammer', ['tenants']],
        '10.5' => ['estate-management', 'Estates, HOAs & levies', 'fence', []],
        '10.11' => ['coworking', 'Co-working & desk booking', 'armchair', []],
        '10.14' => ['tenant-portal', 'Tenant portal', 'door-open', ['tenants']],
        '11.1' => ['construction', 'Construction projects & BOQ', 'hard-hat', ['projects']],
        '11.9' => ['contractor-jobs', 'Contractor job cards', 'wrench', []],
        '12.1' => ['ticketing', 'Online ticketing', 'ticket', []],
        '12.2' => ['event-registration', 'Event registration', 'calendar-plus', []],
        '12.3' => ['venue-booking', 'Venue booking', 'map-pin', []],
        '12.4' => ['memberships', 'Memberships & clubs', 'badge-check', []],
        '12.5' => ['church', 'Church & ministry', 'church', []],
        '12.7' => ['ngo', 'NGO, donors & grants', 'heart-handshake', []],
        '12.8' => ['fundraising', 'Fundraising pages', 'hand-heart', []],
        '12.10' => ['volunteers', 'Volunteers', 'hand-helping', []],
        '12.12' => ['sports-leagues', 'Sports leagues', 'trophy', []],
        '13.1' => ['appointments', 'Appointments & bookings', 'calendar-check', []],
        '13.2' => ['salon', 'Salon, spa & barber', 'scissors', ['appointments']],
        '13.3' => ['gym', 'Gym & fitness', 'dumbbell', ['memberships']],
        '13.4' => ['field-service', 'Field service & job cards', 'wrench', []],
        '13.6' => ['legal', 'Legal practice', 'scale', ['time-tracking']],
        '13.8' => ['garage', 'Garage & workshop', 'car', ['inventory']],
        '13.10' => ['laundry', 'Laundry & dry-cleaning', 'shirt', []],
        '13.12' => ['photography', 'Photography studio', 'camera', ['appointments']],
        '13.15' => ['security-company', 'Security company', 'shield-check', []],
        '13.16' => ['it-services', 'IT services (MSP)', 'server', ['helpdesk']],
        '13.17' => ['hosting-billing', 'Web & hosting billing', 'globe', ['recurring-billing']],
        '13.19' => ['recruitment-agency', 'Recruitment agency', 'user-search', []],
        '14.1' => ['online-store', 'Online store', 'store', ['inventory']],
        '14.2' => ['marketplace', 'Multi-vendor marketplace', 'shopping-basket', ['online-store']],
        '14.3' => ['catalog', 'Product catalogue', 'tags', ['inventory']],
        '14.6' => ['grocery-pos', 'Supermarket POS', 'barcode', ['pos']],
        '14.11' => ['vehicle-dealership', 'Vehicle dealership', 'car', ['inventory']],
        '15.1' => ['farm', 'Farm & crops', 'tractor', []],
        '15.2' => ['livestock', 'Livestock', 'beef', []],
        '15.3' => ['cooperative', 'Cooperative & members', 'users-round', []],
        '15.4' => ['produce-sales', 'Produce sales & payouts', 'wheat', []],
        '15.5' => ['poultry', 'Poultry', 'egg', []],
        '15.6' => ['dairy', 'Dairy & milk collection', 'milk', []],
        '16.1' => ['taxi', 'Taxi & ride-hailing', 'car-taxi-front', []],
        '16.2' => ['kombi-collections', 'Minibus daily collections', 'bus-front', []],
        '16.6' => ['haulage', 'Haulage & trucking', 'truck', ['fleet']],
        '16.10' => ['gps-tracking', 'GPS tracking', 'map-pinned', []],
        '17.1' => ['utility-billing', 'Utility billing & meters', 'gauge', []],
        '17.2' => ['isp-billing', 'ISP & hotspot billing', 'wifi', []],
        '18.1' => ['permits', 'Permits & licences', 'file-check-2', []],
        '18.2' => ['compliance', 'Compliance & audits', 'clipboard-check', []],
        '18.5' => ['visitors', 'Visitor management', 'id-card', []],
        '18.7' => ['council-revenue', 'Council revenue', 'landmark', []],
        '20.1' => ['personal-finance', 'Personal finance', 'piggy-bank', []],
        '20.2' => ['personal-tasks', 'Personal tasks & habits', 'list-checks', []],
    ];

    /** Extra core rows not in the markdown. */
    protected array $extraCore = [
        ['0.31', 'contacts', 'Contacts & directory', 'Customers, suppliers, patients, students, tenants, members — one shared address book for every app.', 'contact'],
        ['0.32', 'dashboard', 'Dashboard', 'Home screen with widgets from every enabled app.', 'layout-dashboard'],
    ];

    public function run(): void
    {
        $lines = File::lines(base_path('docs/01-MODULE-CATALOGUE.md'));

        $suiteModels = [];
        foreach ($this->suites as $n => [$key, $name, $icon]) {
            $suiteModels[$n] = Suite::updateOrCreate(['key' => $key], [
                'name' => $name, 'icon' => $icon, 'sort_order' => $n,
                'description' => null,
            ]);
        }

        $usedKeys = [];
        $currentSuite = null;
        $order = 0;

        foreach ($lines as $line) {
            if (preg_match('/^## (\d+)\. /', $line, $m)) {
                $n = (int) $m[1];
                $currentSuite = $suiteModels[$n] ?? null;
                $order = 0;

                continue;
            }
            if (! $currentSuite || ! preg_match('/^\| (\d+\.\d+) \| (.+?) \| (.+?) \|$/', trim($line), $m)) {
                continue;
            }

            [, $ref, $raw, $status] = $m;
            $order++;
            $this->upsert($currentSuite, $ref, $raw, $order, $usedKeys);
        }

        foreach ($this->extraCore as [$ref, $key, $name, $description, $icon]) {
            Module::updateOrCreate(['key' => $key], [
                'suite_id' => $suiteModels[0]->id, 'ref' => $ref, 'name' => $name, 'description' => $description,
                'icon' => $icon, 'is_core' => true, 'is_installed' => true, 'status' => Module::STATUS_AVAILABLE,
                'depends' => [], 'tags' => [], 'sort_order' => 100 + (int) substr($ref, 2),
            ]);
        }

        Cache::forget('modules.installed_keys');
    }

    protected function upsert(Suite $suite, string $ref, string $raw, int $order, array &$usedKeys): void
    {
        $isCore = $suite->sort_order === 0;
        $raw = trim(str_replace(['★', '➕', '✔'], '', $raw));

        $links = null;
        if (preg_match('/\((links to [^)]+|lite [^)]+)\)/i', $raw, $lm)) {
            $links = ucfirst($lm[1]);
            $raw = trim(str_replace($lm[0], '', $raw));
        }

        [$name, $description] = $this->split($raw);
        $icon = $suite->icon;
        $depends = [];
        $key = null;

        if (isset($this->keys[$ref])) {
            [$key, $name, $icon, $depends] = $this->keys[$ref];
            if ($description === '' || $description === $name) {
                $description = $this->split($raw)[1] ?: $raw;
            }
        }

        if ($description === '') {
            $description = $links ?? $name;
        } elseif ($links) {
            $description .= ' ('.$links.')';
        }

        $key ??= $this->autoKey($name, $ref, $usedKeys);
        $usedKeys[$key] = true;
        // Blueprint apps can need other modules too (e.g. Invoicing to bill clinic visits).
        $depends = array_values(array_unique([...$depends, ...(app(BlueprintRegistry::class)->get($key)?->depends ?? [])]));

        $tags = collect(preg_split('/[,;\/]/', strtolower($description)))
            ->map(fn ($t) => trim($t))->filter(fn ($t) => $t !== '' && strlen($t) < 40)->take(8)->values()->all();

        Module::updateOrCreate(['key' => $key], [
            'suite_id' => $suite->id,
            'ref' => $ref,
            'name' => $name,
            'description' => Str::limit($description, 240, ''),
            'icon' => $icon,
            'is_core' => $isCore,
            'is_installed' => $isCore,
            'status' => $isCore ? Module::STATUS_AVAILABLE : Module::STATUS_COMING_SOON,
            'depends' => $depends,
            'tags' => $tags,
            'sort_order' => $order,
        ]);
    }

    /** "Name: details" | "Name → details" | "Name, a, b" => [name, description] */
    protected function split(string $raw): array
    {
        foreach ([': ', ' → ', ' - '] as $sep) {
            if (str_contains($raw, $sep)) {
                [$name, $desc] = explode($sep, $raw, 2);

                return [trim($name), ucfirst(trim($desc))];
            }
        }
        if (str_contains($raw, ', ')) {
            $name = Str::before($raw, ', ');

            return [trim($name), ucfirst(trim($raw))];
        }

        return [trim($raw), ''];
    }

    protected function autoKey(string $name, string $ref, array $used): string
    {
        $base = Str::slug(Str::words(preg_replace('/[&\/]/', ' ', $name), 4, ''));
        $base = Str::limit($base, 48, '');
        $key = $base ?: 'module';
        if (isset($used[$key]) || Module::where('key', $key)->where('ref', '!=', $ref)->exists()) {
            $key = $base.'-'.str_replace('.', '-', $ref);
        }

        return $key;
    }
}
