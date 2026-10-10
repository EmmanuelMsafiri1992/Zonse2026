<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Plan;
use App\Models\Profession;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\WorkspaceContext;
use Illuminate\Database\Seeder;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Models\Service;
use Modules\Contacts\Models\Contact;
use Modules\Helpdesk\Models\Ticket;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Item;
use Modules\Invoicing\Models\Payment;
use Modules\Invoicing\Models\Quote;
use Modules\Invoicing\Models\TaxRate;
use Modules\Tasks\Models\Task;

/**
 * Local-only: a super admin, a demo owner and a fully onboarded demo clinic with sample data.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new \RuntimeException('DemoSeeder creates logins with the password "password" and must never run in production.');
        }

        $admin = User::updateOrCreate(['email' => 'admin@zonseo.test'], [
            'name' => 'Zonseob Admin', 'password' => 'password', 'is_super_admin' => true, 'email_verified_at' => now(),
        ]);

        $owner = User::updateOrCreate(['email' => 'demo@zonseo.test'], [
            'name' => 'Tariro Moyo', 'password' => 'password', 'email_verified_at' => now(),
        ]);

        $profession = Profession::where('key', 'doctor-clinic')->first();
        $plan = Plan::where('key', 'business')->first();

        $workspace = Workspace::firstOrCreate(['slug' => 'sunrise-clinic'], [
            'name' => 'Sunrise Family Clinic', 'type' => 'clinic', 'owner_id' => $owner->id,
            'profession_id' => $profession?->id, 'email' => 'hello@sunriseclinic.test', 'phone' => '+263 77 000 0000',
            'city' => 'Harare', 'country_code' => 'ZW', 'currency_code' => 'USD', 'timezone' => 'Africa/Harare',
            'locale' => 'en', 'onboarding_step' => 0, 'onboarded_at' => now(), 'trial_ends_at' => now()->addDays(14), 'is_active' => true,
        ]);

        if ($workspace->wasRecentlyCreated) {
            $workspace->members()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
            $workspace->members()->attach($admin->id, ['role' => 'admin', 'joined_at' => now()]);
            Branch::create(['workspace_id' => $workspace->id, 'name' => 'Avondale', 'code' => 'AVD', 'city' => 'Harare', 'is_default' => true, 'is_active' => true]);
            Branch::create(['workspace_id' => $workspace->id, 'name' => 'Borrowdale', 'code' => 'BRD', 'city' => 'Harare', 'is_default' => false, 'is_active' => true]);

            if ($profession) {
                $workspace->enableModules($profession->module_keys, $owner);
            }
            if ($plan) {
                $workspace->subscriptions()->create([
                    'plan_id' => $plan->id, 'status' => 'trialing', 'billing_cycle' => 'monthly',
                    'amount' => $plan->price_monthly, 'currency' => 'USD', 'gateway' => 'manual',
                    'trial_ends_at' => now()->addDays(14), 'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
                ]);
            }
            $owner->switchWorkspace($workspace);
            $admin->switchWorkspace($workspace);
        }

        $workspace->enableModules(['contacts', 'invoicing', 'quotes', 'appointments', 'tasks', 'helpdesk', 'clinic'], $owner);

        app(WorkspaceContext::class)->run($workspace, function () use ($workspace, $owner): void {
            $this->seedContacts($workspace, $owner);
            $this->seedSales($workspace, $owner);
            $this->seedBookings($workspace, $owner);
            $this->seedTasks($workspace, $owner);
            $this->seedTickets($workspace, $owner);
            $this->seedClinic($workspace, $owner);
        });
    }

    /** Sample patients, visits and prescriptions for the "Clinic & patients" blueprint app. */
    protected function seedClinic(Workspace $workspace, User $owner): void
    {
        if (Record::query()->ofBlueprint('clinic')->exists()) {
            return;
        }

        $customers = Contact::query()->where('type', 'customer')->orderBy('id')->get();
        $base = ['workspace_id' => $workspace->id, 'blueprint' => 'clinic', 'created_by' => $owner->id];

        $patients = [];
        foreach ([
            ['Rudo Chikwanha', $customers[0] ?? null, ['date_of_birth' => '1986-03-14', 'sex' => 'female', 'phone' => '+263 77 123 4567', 'medical_aid' => 'CIMAS', 'medical_aid_number' => 'CIM-448812', 'allergies' => 'Penicillin']],
            ['Tendai Moyo', $customers[1] ?? null, ['date_of_birth' => '1979-11-02', 'sex' => 'male', 'phone' => '+263 71 555 0101', 'medical_aid' => 'PSMAS', 'medical_aid_number' => 'PS-90021', 'chronic_conditions' => 'Hypertension']],
            ['Chipo Ncube', $customers[2] ?? null, ['date_of_birth' => '2015-06-21', 'sex' => 'female', 'next_of_kin' => 'Grace Ncube', 'next_of_kin_phone' => '+263 24 2700 100']],
            ['Simba Dube', $customers[3] ?? null, ['date_of_birth' => '1992-01-30', 'sex' => 'male', 'phone' => '+263 77 900 2222', 'medical_aid' => 'First Mutual']],
        ] as [$name, $contact, $data]) {
            $patients[] = Record::create($base + ['entity' => 'patients', 'title' => $name, 'status' => 'active', 'contact_id' => $contact?->id, 'data' => $data]);
        }

        $visits = [];
        foreach ([
            [0, 'Persistent cough', 'completed', 3, 35, ['complaint' => 'Dry cough for two weeks, worse at night.', 'temperature' => 37.2, 'diagnosis' => 'Upper respiratory tract infection', 'treatment' => 'Rest, fluids, antibiotics for 5 days.']],
            [1, 'Blood pressure review', 'completed', 1, 25, ['blood_pressure' => '148/95', 'weight' => 86, 'diagnosis' => 'Hypertension, poorly controlled', 'follow_up' => now()->addWeeks(4)->toDateString()]],
            [2, 'Fever and rash', 'in_consultation', 0, 35, ['complaint' => 'Fever since yesterday, rash on arms.', 'temperature' => 38.6]],
            [3, 'Sports injury, left ankle', 'waiting', 0, 35, ['complaint' => 'Twisted ankle playing football.']],
        ] as [$patient, $reason, $status, $daysAgo, $fee, $data]) {
            $visits[] = Record::create($base + [
                'entity' => 'visits', 'title' => $reason, 'status' => $status, 'assignee_id' => $owner->id, 'amount' => $fee,
                'currency' => $workspace->currency_code, 'occurs_on' => now()->subDays($daysAgo)->toDateString(),
                'data' => ['patient' => $patients[$patient]->id] + $data,
            ]);
        }

        foreach ([
            [0, 0, 'Amoxicillin 500mg', 'completed', ['dosage' => '1 capsule', 'frequency' => 'three_times_daily', 'duration_days' => 5]],
            [1, 1, 'Amlodipine 10mg', 'active', ['dosage' => '1 tablet', 'frequency' => 'once_daily', 'duration_days' => 30, 'instructions' => 'Take in the morning.']],
            [1, 1, 'Hydrochlorothiazide 12.5mg', 'active', ['dosage' => '1 tablet', 'frequency' => 'once_daily', 'duration_days' => 30]],
        ] as [$patient, $visit, $medicine, $status, $data]) {
            Record::create($base + [
                'entity' => 'prescriptions', 'title' => $medicine, 'status' => $status, 'occurs_on' => $visits[$visit]->occurs_on,
                'data' => ['patient' => $patients[$patient]->id, 'visit' => $visits[$visit]->id] + $data,
            ]);
        }
    }

    protected function seedContacts(Workspace $workspace, User $owner): void
    {
        if (Contact::query()->exists()) {
            return;
        }

        $rows = [
            ['type' => 'customer', 'kind' => 'person', 'name' => 'Rudo Chikwanha', 'email' => 'rudo@example.com', 'phone' => '+263 77 123 4567', 'city' => 'Harare', 'tags' => ['vip']],
            ['type' => 'customer', 'kind' => 'person', 'name' => 'Tendai Moyo', 'email' => 'tendai@example.com', 'phone' => '+263 71 555 0101', 'city' => 'Harare', 'tags' => []],
            ['type' => 'customer', 'kind' => 'company', 'name' => 'Grace Ncube', 'company_name' => 'Ncube & Daughters Ltd', 'email' => 'accounts@ncube.example', 'phone' => '+263 24 2700 100', 'city' => 'Bulawayo', 'tags' => ['corporate']],
            ['type' => 'customer', 'kind' => 'company', 'name' => 'Farai Dube', 'company_name' => 'Highlands Mining Co.', 'email' => 'farai@highlands.example', 'phone' => '+263 77 900 2222', 'city' => 'Harare', 'tags' => ['corporate', 'medical-aid']],
            ['type' => 'lead', 'kind' => 'person', 'name' => 'Kudzai Mapfumo', 'email' => 'kudzai@example.com', 'phone' => '+263 78 222 3333', 'city' => 'Mutare', 'tags' => []],
            ['type' => 'supplier', 'kind' => 'company', 'name' => 'Peter Banda', 'company_name' => 'MedSupply Africa', 'email' => 'orders@medsupply.example', 'phone' => '+263 24 2777 777', 'city' => 'Harare', 'tags' => ['pharma']],
        ];

        foreach ($rows as $row) {
            Contact::create($row + ['workspace_id' => $workspace->id, 'country_code' => 'ZW', 'currency_code' => 'USD', 'is_active' => true, 'created_by' => $owner->id]);
        }
    }

    protected function seedSales(Workspace $workspace, User $owner): void
    {
        if (Invoice::query()->exists()) {
            return;
        }

        $vat = TaxRate::firstOrCreate(['workspace_id' => $workspace->id, 'name' => 'VAT'], ['rate' => 15, 'is_default' => true, 'is_active' => true]);
        TaxRate::firstOrCreate(['workspace_id' => $workspace->id, 'name' => 'Zero rated'], ['rate' => 0, 'is_default' => false, 'is_active' => true]);

        $workspace->putSetting('invoicing.terms', 'Payment is due within 14 days. Thank you for choosing Sunrise Family Clinic.');
        $workspace->putSetting('invoicing.notes', 'Pay by EcoCash to 0770 000 000 or bank transfer (CBZ, acc 1234567890).');

        $items = [];
        foreach ([
            ['service', 'General consultation', 'Standard consultation with a doctor', 'visit', 25, null],
            ['service', 'Specialist consultation', 'Consultation with a visiting specialist', 'visit', 60, null],
            ['service', 'Blood pressure & sugar screening', null, 'test', 10, null],
            ['service', 'Antenatal visit', 'Routine antenatal check-up', 'visit', 35, null],
            ['product', 'Paracetamol 500mg (20)', 'Box of 20 tablets', 'box', 3.5, 1.9],
            ['product', 'Amoxicillin 250mg (21)', 'Course of 21 capsules', 'pack', 8, 4.2],
            ['service', 'Dressing & wound care', null, 'session', 15, null],
        ] as [$type, $name, $description, $unit, $price, $cost]) {
            $items[$name] = Item::create([
                'workspace_id' => $workspace->id, 'type' => $type, 'name' => $name, 'description' => $description, 'unit' => $unit,
                'price' => $price, 'cost' => $cost, 'tax_rate_id' => $type === 'product' ? $vat->id : null, 'is_active' => true,
            ]);
        }

        $customers = Contact::query()->whereIn('type', ['customer'])->orderBy('id')->get();
        $line = fn (string $name, float $qty = 1) => [
            'item_id' => $items[$name]->id, 'description' => $items[$name]->name, 'quantity' => $qty, 'unit' => $items[$name]->unit,
            'unit_price' => $items[$name]->price, 'tax_rate' => $items[$name]->taxRate?->rate ?? 0,
        ];

        $plans = [
            ['contact' => $customers[0], 'issued' => 40, 'status' => 'paid', 'lines' => [$line('General consultation'), $line('Paracetamol 500mg (20)', 2)]],
            ['contact' => $customers[1], 'issued' => 25, 'status' => 'overdue', 'lines' => [$line('Specialist consultation'), $line('Blood pressure & sugar screening')]],
            ['contact' => $customers[2], 'issued' => 10, 'status' => 'partial', 'lines' => [$line('Antenatal visit', 3), $line('Amoxicillin 250mg (21)', 2)], 'discount' => ['percent', 10]],
            ['contact' => $customers[3], 'issued' => 3, 'status' => 'sent', 'lines' => [$line('General consultation', 4), $line('Dressing & wound care', 2)]],
            ['contact' => $customers[0], 'issued' => 0, 'status' => 'draft', 'lines' => [$line('General consultation')]],
        ];

        foreach ($plans as $plan) {
            $invoice = Invoice::create([
                'workspace_id' => $workspace->id, 'contact_id' => $plan['contact']->id, 'created_by' => $owner->id,
                'issue_date' => today()->subDays($plan['issued']), 'due_date' => today()->subDays($plan['issued'])->addDays(14),
                'discount_type' => $plan['discount'][0] ?? null, 'discount_value' => $plan['discount'][1] ?? 0,
                'terms' => $workspace->setting('invoicing.terms'), 'notes' => $workspace->setting('invoicing.notes'),
            ]);
            $invoice->syncLines($plan['lines']);

            if ($plan['status'] !== 'draft') {
                $invoice->markSent();
                $invoice->forceFill(['sent_at' => $invoice->issue_date->copy()->setTime(9, 0)])->saveQuietly();
            }
            if ($plan['status'] === 'paid') {
                Payment::create(['workspace_id' => $workspace->id, 'invoice_id' => $invoice->id, 'amount' => $invoice->total, 'paid_on' => $invoice->issue_date->copy()->addDays(5), 'method' => 'mobile_money', 'reference' => 'ECO-4471', 'received_by' => $owner->id]);
            }
            if ($plan['status'] === 'partial') {
                Payment::create(['workspace_id' => $workspace->id, 'invoice_id' => $invoice->id, 'amount' => round($invoice->total / 2, 2), 'paid_on' => today()->subDays(2), 'method' => 'bank', 'reference' => 'CBZ-99120', 'received_by' => $owner->id]);
            }
        }
        Invoice::refreshOverdue();

        $quoteA = Quote::create(['workspace_id' => $workspace->id, 'contact_id' => $customers[3]->id, 'created_by' => $owner->id, 'issue_date' => today()->subDays(5), 'valid_until' => today()->addDays(25), 'reference' => 'Staff wellness day', 'notes' => 'Covers up to 40 employees on site.']);
        $quoteA->syncLines([$line('Blood pressure & sugar screening', 40), $line('General consultation', 10)]);
        $quoteA->markSent();

        $quoteB = Quote::create(['workspace_id' => $workspace->id, 'contact_id' => $customers[2]->id, 'created_by' => $owner->id, 'issue_date' => today()->subDays(20), 'valid_until' => today()->subDays(2)]);
        $quoteB->syncLines([$line('Antenatal visit', 6)]);
        $quoteB->markSent();
        Quote::refreshExpired();

        $quoteC = Quote::create(['workspace_id' => $workspace->id, 'contact_id' => $customers[1]->id, 'created_by' => $owner->id, 'issue_date' => today(), 'valid_until' => today()->addDays(30)]);
        $quoteC->syncLines([$line('Specialist consultation', 2)]);
    }

    protected function seedBookings(Workspace $workspace, User $owner): void
    {
        if (Service::query()->exists()) {
            return;
        }

        $general = Service::create(['workspace_id' => $workspace->id, 'name' => 'General consultation', 'description' => 'Walk-in or booked GP visit.', 'duration_minutes' => 20, 'price' => 25, 'color' => '#0ea5a4']);
        $specialist = Service::create(['workspace_id' => $workspace->id, 'name' => 'Specialist consultation', 'description' => 'Referral review with the visiting specialist.', 'duration_minutes' => 30, 'price' => 60, 'color' => '#2563eb']);
        $antenatal = Service::create(['workspace_id' => $workspace->id, 'name' => 'Antenatal visit', 'description' => 'Routine antenatal check and scan.', 'duration_minutes' => 30, 'price' => 35, 'color' => '#db2777']);
        Service::create(['workspace_id' => $workspace->id, 'name' => 'Flu vaccination', 'duration_minutes' => 10, 'price' => 12, 'color' => '#16a34a']);

        $customers = Contact::query()->where('type', 'customer')->orderBy('id')->get();
        $day = fn (int $offset, string $time): string => Appointment::localNow()->addDays($offset)->format('Y-m-d').' '.$time;
        $branch = Branch::query()->where('workspace_id', $workspace->id)->first();

        $rows = [
            [$customers[0], $general, $day(-1, '09:00'), 'completed'],
            [$customers[1], $antenatal, $day(-1, '11:30'), 'no_show'],
            [$customers[3], $specialist, $day(-3, '14:00'), 'completed'],
            [$customers[2], $general, $day(0, '08:30'), 'completed'],
            [$customers[0], $specialist, $day(0, '10:00'), 'confirmed'],
            [$customers[1], $general, $day(0, '15:30'), 'scheduled'],
            [$customers[3], $antenatal, $day(1, '09:30'), 'confirmed'],
            [$customers[2], $general, $day(2, '11:00'), 'scheduled'],
            [$customers[0], $general, $day(7, '10:00'), 'scheduled'],
            [$customers[1], $specialist, $day(3, '13:00'), 'cancelled'],
        ];

        foreach ($rows as [$contact, $service, $startsAt, $status]) {
            $appointment = Appointment::create([
                'workspace_id' => $workspace->id, 'branch_id' => $branch?->id, 'contact_id' => $contact->id, 'service_id' => $service->id,
                'staff_id' => $owner->id, 'starts_at' => $startsAt, 'ends_at' => date('Y-m-d H:i:s', strtotime($startsAt) + $service->duration_minutes * 60),
                'price' => $service->price, 'created_by' => $owner->id,
            ]);
            if ($status !== 'scheduled') {
                $appointment->transitionTo($status, $status === 'cancelled' ? 'Customer asked to move the visit.' : null);
            }
        }
    }

    protected function seedTasks(Workspace $workspace, User $owner): void
    {
        if (Task::query()->exists()) {
            return;
        }

        $customers = Contact::query()->where('type', 'customer')->orderBy('id')->get();
        $day = fn (int $offset): string => Task::localToday()->addDays($offset)->toDateString();

        $rows = [
            ['Chase Highlands Mining for the overdue invoice', 'urgent', 'in_progress', $day(-2), $customers[3], 'They promised payment by Friday. Ring accounts if nothing lands.'],
            ['Call Rudo with her results', 'high', 'todo', $day(0), $customers[0], null],
            ['Order more flu vaccine stock', 'high', 'todo', $day(1), null, '40 doses should see us through the month.'],
            ['Send Ncube & Daughters the wellness-day quote', 'normal', 'todo', $day(3), $customers[2], null],
            ['Update the price list on the front desk', 'low', 'todo', null, null, null],
            ["Confirm tomorrow's antenatal visits by SMS", 'normal', 'in_progress', $day(0), null, null],
            ['Renew the clinic practising certificate', 'normal', 'todo', $day(21), null, null],
            ["File last month's medical aid claims", 'high', 'done', $day(-4), null, null],
            ['Book the fridge service', 'low', 'done', $day(-1), null, null],
        ];

        foreach ($rows as $index => [$title, $priority, $status, $due, $contact, $description]) {
            Task::create([
                'workspace_id' => $workspace->id, 'title' => $title, 'description' => $description, 'priority' => $priority, 'status' => $status,
                'due_date' => $due, 'contact_id' => $contact?->id, 'assignee_id' => $owner->id, 'position' => $index, 'created_by' => $owner->id,
            ]);
        }
    }

    protected function seedTickets(Workspace $workspace, User $owner): void
    {
        if (Ticket::query()->exists()) {
            return;
        }

        $customers = Contact::query()->where('type', 'customer')->orderBy('id')->get();
        $branch = Branch::query()->where('workspace_id', $workspace->id)->orderBy('id')->first();
        $admin = User::query()->where('email', 'admin@zonseo.test')->first();

        $rows = [
            ['Charged twice for the specialist consultation', 'Billing', 'email', 'urgent', 'open', $customers[1], null, $owner, 3, 'The statement shows two charges of $60 for the same visit on the 12th. Please refund one.', []],
            ['Need a copy of last month invoice for medical aid', 'Billing', 'phone', 'normal', 'pending', $customers[0], null, $owner, 26, 'Medical aid wants an itemised invoice before they pay out.', [
                ['reply', 'Sending the itemised copy to your email this afternoon.'],
                ['note', 'Needs the ICD-10 codes on it, ask Dr Moyo.'],
            ]],
            ['Wellness day: can we get 40 flu shots on site?', 'Corporate', 'whatsapp', 'high', 'open', $customers[2], null, $admin, 5, 'HR asked whether the clinic can come to the office in two weeks.', [
                ['reply', 'Yes, we can. I will send a quote for 40 doses plus a nurse for the morning.'],
                ['customer', 'Great, please include a second nurse so it goes faster.'],
            ]],
            ['Waiting room chairs are broken', 'Facilities', 'walk_in', 'low', 'open', null, 'Mrs Chikwanha', null, 50, 'Two of the chairs by the window wobble badly.', []],
            ['Results not uploaded to the patient portal', 'Results', 'web', 'high', 'resolved', $customers[0], null, $owner, 72, 'Blood test from Monday is not showing.', [
                ['reply', 'The lab had a delay. Results are now on your portal.'],
            ]],
            ['Query about antenatal package pricing', 'Pricing', 'phone', 'normal', 'closed', null, 'Faith Dube', $admin, 120, 'Asked what the full antenatal package costs and whether it can be paid monthly.', [
                ['reply', 'It is $35 per visit or $300 for the full package, payable in three instalments.'],
                ['customer', 'Thanks, I will book next week.'],
            ]],
        ];

        foreach ($rows as [$subject, $category, $channel, $priority, $status, $contact, $requester, $assignee, $hoursAgo, $body, $thread]) {
            $openedAt = now()->subHours($hoursAgo);

            $ticket = Ticket::create([
                'workspace_id' => $workspace->id, 'branch_id' => $branch?->id, 'subject' => $subject, 'body' => $body, 'category' => $category,
                'channel' => $channel, 'priority' => $priority, 'contact_id' => $contact?->id, 'requester_name' => $requester,
                'assignee_id' => $assignee?->id, 'created_by' => $owner->id,
            ]);

            foreach ($thread as $step => [$kind, $text]) {
                match ($kind) {
                    'note' => $ticket->note($text, $assignee ?? $owner),
                    'customer' => $ticket->customerReply($text),
                    default => $ticket->reply($text, $assignee ?? $owner),
                };
                $ticket->comments()->latest('id')->first()?->forceFill(['created_at' => $openedAt->copy()->addHours($step + 1)])->save();
            }

            if ($ticket->status !== $status) {
                $ticket->update(['status' => $status]);
            }

            $ticket->forceFill([
                'created_at' => $openedAt,
                'first_replied_at' => $ticket->first_replied_at ? $openedAt->copy()->addHour() : null,
                'last_activity_at' => $openedAt->copy()->addHours(count($thread)),
            ])->saveQuietly();
        }
    }
}
