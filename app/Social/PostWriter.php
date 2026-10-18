<?php

namespace App\Social;

use App\Blueprints\Blueprint;
use App\Blueprints\Entity;
use App\Models\Module;
use App\Models\Profession;
use App\Models\Suite;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Writes the posts in the "how Zonseob works" series: the platform introduction, one post per
 * suite and one per app. Everything about an app comes from the catalogue row and its blueprint,
 * so the posts stay true to what the software actually does.
 */
class PostWriter
{
    public const SITE = 'https://zonseob.com';

    public const REGISTER = 'https://zonseob.com/register';

    /** @var Collection<int, Profession>|null */
    protected ?Collection $professions = null;

    /** @var array<string, string>|null module key => name */
    protected ?array $moduleNames = null;

    /**
     * The hand-written opening posts: what the platform is and how its pieces fit together.
     *
     * @return list<PostDraft>
     */
    public function intros(int $appCount, int $suiteCount): array
    {
        $apps = $appCount > 0 ? number_format($appCount) : 'hundreds of';
        $suites = $suiteCount > 0 ? (string) $suiteCount : 'twenty';
        $sign = 'Start free at '.self::REGISTER.' or read more at '.self::SITE.".\n\n#Zonseob #BusinessSoftware #OnePlatform";

        return [
            new PostDraft('intro', 'welcome', 'Welcome', 'One platform, every profession',
                "Welcome to Zonseob.\n\n"
                ."Zonseob is one business platform with {$apps} ready-made apps across {$suites} suites: clinics and pharmacies, schools, shops and restaurants, farms, builders, churches, law firms, workshops, transport companies and many more.\n\n"
                ."You do not install everything. You pick the apps your work needs, and they share one address book, one set of invoices, one calendar and one team. Add another app the day you need it.\n\n"
                ."Over the coming weeks this page will walk through the platform suite by suite and app by app, so you can see exactly how each part works before you try it.\n\n".$sign,
                ["{$apps} apps across {$suites} suites", 'Pick only the apps you need', 'One address book, one set of invoices, one team', 'Free plan, no card needed']),

            new PostDraft('intro', 'how-it-is-organised', 'How it works', 'Suites, apps and records',
                "How Zonseob is organised, in three layers.\n\n"
                ."1. Suites. Apps are grouped by the kind of work they serve: Finance, Sales, Healthcare, Education, Property, Construction and so on.\n\n"
                ."2. Apps. Each app covers one job: a clinic's patient files, a school's fee accounts, a workshop's job cards, a farm's harvests. Switch an app on from Settings, Apps & modules, and it appears in your menu.\n\n"
                ."3. Records. Inside an app you work with records: patients, visits, invoices, bookings, jobs. Every record has a number, a status, the people it belongs to, attachments and a full history of who changed what.\n\n"
                ."Every app follows the same layout, so once you have learned one you have learned them all.\n\n".$sign,
                ['Suites group apps by profession', 'Apps cover one job each', 'Records: numbered, with status and history', 'Same layout everywhere']),

            new PostDraft('intro', 'shared-contacts', 'How it works', 'One shared address book',
                "Everything in Zonseob starts with people.\n\n"
                ."Customers, suppliers, patients, students, tenants, members and staff live in one Contacts directory that every app shares. Add a patient in the clinic app and the same person is already there when you raise an invoice, book an appointment or log a support ticket.\n\n"
                ."No double entry, no mismatched spellings, and one place to see everything you have ever done with someone: their invoices, bookings, cases and notes on a single page.\n\n".$sign,
                ['Customers, suppliers, patients, students, tenants', 'Shared by every app', 'One page shows everything about a person', 'No double entry']),

            new PostDraft('intro', 'invoicing-everywhere', 'How it works', 'Invoices and payments in every app',
                "Money flows through one Invoicing engine.\n\n"
                ."Quotations, invoices, receipts and a catalogue of what you sell are built in. Other apps bill through it: a clinic visit, a school fee account, a repair job or a venue booking becomes an invoice with one click, and when the invoice is paid the record it came from is marked paid too.\n\n"
                ."Invoices carry your logo and brand colour, go out by email or as a link, and payments can be recorded by hand or taken online.\n\n".$sign,
                ['Quotes, invoices, receipts, catalogue', 'Any record can be billed in one click', 'Payment marks the original record paid', 'Your logo and colours']),

            new PostDraft('intro', 'appointments-calendar', 'How it works', 'Appointments and the calendar',
                "One calendar for the whole business.\n\n"
                ."The Appointments app gives you a week view for your staff, a list of services with durations and prices, confirmations, reminders and no-show tracking. Apps that schedule things, such as clinic visits, salon bookings, driving lessons or site inspections, place their records on the same calendar.\n\n"
                ."Customers can be sent text reminders before their time, and anything that moves is logged.\n\n".$sign,
                ['Week calendar for your staff', 'Services with durations and prices', 'Text reminders and no-show tracking', 'Shared by every app that schedules']),

            new PostDraft('intro', 'tasks-helpdesk', 'How it works', 'Tasks and Helpdesk for your team',
                "Two apps keep a team moving.\n\n"
                ."Tasks is a to-do list and kanban board with priorities, due dates and assignees, and each task can link to the customer or record it is about.\n\n"
                ."Helpdesk turns customer questions into tickets with replies, internal notes, priorities and assignment, with a queue that shows what is waiting on you.\n\n"
                ."Both work on their own and inside other apps, so a late payment, a complaint or a follow-up visit becomes something a named person owns.\n\n".$sign,
                ['To-do list and kanban board', 'Tickets with replies and internal notes', 'Priorities, due dates, assignees', 'Linked to customers and records']),

            new PostDraft('intro', 'team-branches-currencies', 'How it works', 'Your team, branches and currencies',
                "Built for real businesses, not just one person at one desk.\n\n"
                ."Invite your team and give each person a role, so a cashier, a nurse and an accountant see only what they should. Run more than one branch and keep each branch's records separate while head office sees the whole picture. Price and pay in more than one currency.\n\n"
                ."Customers, patients, parents and tenants can be given a portal to see their own invoices, statements and bookings without calling you.\n\n".$sign,
                ['Roles for every team member', 'Several branches, one overview', 'Multi-currency', 'Portals for your customers']),

            new PostDraft('intro', 'zonseob-ai', 'How it works', 'Zonseob AI',
                "Zonseob comes with an assistant built in.\n\n"
                ."Zonseob AI, powered by Claude, answers questions about your work, drafts emails and messages, explains a report or helps you set up an app. Every new account starts with 20,000 free tokens, and you buy more only if you want them: a starter pack of 250,000 tokens costs $5.\n\n"
                ."Your conversations are private to you.\n\n".$sign,
                ['Powered by Claude', '20,000 free tokens to start', 'Drafts, explains, sets up', 'Private to you']),

            new PostDraft('intro', 'plans', 'Getting started', 'Plans: start free, grow when ready',
                "Zonseob pricing is simple.\n\n"
                ."Free: $0. Four starter apps, one member, one branch, 1 GB of storage. Enough for a sole trader to run properly.\n"
                ."Solo: $9 a month. More apps and storage for one person.\n"
                ."Business: $29 a month. Your whole team, several branches, every app.\n"
                ."Enterprise: $99 a month. Unlimited scale, priority support.\n\n"
                ."Pay yearly and get two months free. Every paid plan starts with a 14-day free trial and no card is needed. Pay by card, Paynow or bank transfer in your local currency.\n\n".$sign,
                ['Free: $0, four apps', 'Solo $9, Business $29, Enterprise $99 a month', '14-day trial, no card', 'Card, Paynow or bank transfer']),

            new PostDraft('intro', 'security', 'How it works', 'Your data, kept safe',
                "Security is part of the platform, not an add-on.\n\n"
                ."Sign in with a passkey, two-factor codes or your Google or Microsoft account. Every change to every record is logged with who made it and when. Daily backups run automatically, and your workspace's data is separated from everyone else's.\n\n"
                ."Role-based permissions mean a person sees only the apps and records their job needs.\n\n".$sign,
                ['Passkeys, 2FA, Google and Microsoft sign-in', 'Full activity log', 'Daily backups', 'Role-based permissions']),

            new PostDraft('intro', 'starter-bundles', 'Getting started', 'Pick your profession, get a starter bundle',
                "You do not have to choose from hundreds of apps on day one.\n\n"
                ."When you sign up, tell Zonseob what you do: doctor, pharmacy, school, restaurant, lawyer, farmer, builder, church, salon, transport operator and many more. You get a starter bundle of the apps that profession normally needs, already switched on and linked together.\n\n"
                ."Change the bundle any time from Settings, Apps & modules.\n\n".$sign,
                ['Choose your profession at sign-up', 'Apps switched on and linked', 'Change the bundle any time']),

            new PostDraft('intro', 'partners', 'Getting started', 'Earn with the partner programme',
                "Do you set up systems for other businesses?\n\n"
                ."Accountants, IT consultants and resellers can join the Zonseob partner programme. Bring a business onto Zonseob and earn 20% of what they pay, for as long as they stay. Your own branding can appear on the workspaces you manage.\n\n"
                ."Next up on this page: a tour through each suite, starting with the core apps every business uses.\n\n".$sign,
                ['20% recurring commission', 'Your branding on client workspaces', 'Suite tour starts next']),
        ];
    }

    /**
     * Introduces a suite and names the apps in it before each gets its own post.
     *
     * @param  Collection<int, Module>  $modules
     */
    public function suite(Suite $suite, Collection $modules): PostDraft
    {
        $names = $modules->pluck('name')->all();
        $count = count($names);
        $listed = array_slice($names, 0, 8);
        $more = $count - count($listed);

        $message = "{$suite->name}: ".($count === 1 ? 'one app' : "{$count} apps").' for '.$this->suiteAudience($suite).".\n\n";
        if ($suite->description) {
            $message .= $suite->description."\n\n";
        }
        $message .= "In this suite:\n".$this->bulletList($listed);
        if ($more > 0) {
            $message .= "\nand {$more} more.";
        }
        $message .= "\n\nEach app shares the same Contacts, Invoicing and calendar as the rest of Zonseob, so the suite works as one system rather than {$count} separate tools. Over the next posts we go through them one at a time.\n\n";
        $message .= 'Start free at '.self::REGISTER."\n\n#Zonseob #".$this->tag($suite->name);

        return new PostDraft('suite', $suite->key, $count === 1 ? 'Suite · 1 app' : "Suite · {$count} apps", $suite->name, $message, array_slice($names, 0, 5));
    }

    /** Explains one app: what it does, what it tracks, what it links to and who it is for. */
    public function app(Module $module, ?Blueprint $blueprint, Suite $suite): PostDraft
    {
        $entities = $blueprint ? array_values($blueprint->entities) : [];
        $description = rtrim(trim($module->description ?: ($blueprint?->description ?? '')), '.');

        $message = "{$module->name}\n{$suite->name} suite\n\n";
        $message .= ($description !== '' ? $description.'.' : '')."\n\n";

        $bullets = [];
        if ($entities) {
            $message .= "What you keep track of:\n";
            foreach (array_slice($entities, 0, 6) as $entity) {
                $message .= '• '.$this->entityLine($entity)."\n";
                $bullets[] = $entity->plural;
            }
            $message .= "\n";
        }

        $links = $this->linkedModules($module);
        if ($links !== []) {
            $message .= 'Works with '.Str::of(implode(', ', $links))->replaceLast(', ', ' and ').$this->linksTail($links).".\n\n";
        }

        $for = $this->professionsFor($module->key);
        if ($for !== []) {
            $message .= 'Part of the starter bundle for '.Str::of(implode(', ', $for))->replaceLast(', ', ' and ').".\n\n";
        }

        $message .= 'How to start: create a free account at '.self::REGISTER.", open Settings, Apps & modules and switch on {$module->name}. It appears in your menu straight away, sharing your contacts and invoices with everything else you use.\n\n";
        $message .= '#Zonseob #'.$this->tag($suite->name).' #'.$this->tag($module->name);

        return new PostDraft('app', $module->key, $suite->name, $module->name, $message, $this->cardBullets($description, $bullets, $links));
    }

    /** The short lines on the card: what the app is, what it tracks, what it works with, how to get it. */
    protected function cardBullets(string $description, array $entities, array $links): array
    {
        $card = [];
        if ($description !== '') {
            $card[] = trim(Str::limit(Str::before($description, '('), 60, ''));
        }
        if ($entities !== []) {
            $card[] = 'Tracks '.Str::lower((string) Str::of(implode(', ', array_slice($entities, 0, 3)))->replaceLast(', ', ' and '));
        }
        if ($links !== []) {
            $card[] = 'Works with '.Str::of(implode(', ', array_slice($links, 0, 2)))->replaceLast(', ', ' and ');
        }
        $card[] = 'Switch it on in Settings, free plan';

        return $card;
    }

    protected function entityLine(Entity $entity): string
    {
        $line = $entity->plural;
        if ($entity->description) {
            return $line.': '.rtrim($entity->description, '.');
        }
        $statuses = array_values($entity->statuses);
        if (count($statuses) > 1) {
            return $line.': '.strtolower(implode(', ', array_slice($statuses, 0, 5)));
        }

        return $line;
    }

    /** @return list<string> names of the modules this one depends on */
    protected function linkedModules(Module $module): array
    {
        $this->moduleNames ??= Module::query()->pluck('name', 'key')->all();

        return collect($module->depends ?? [])
            ->map(fn ($key) => $this->moduleNames[$key] ?? null)
            ->filter()->unique()->values()->all();
    }

    /** @param  list<string>  $links */
    protected function linksTail(array $links): string
    {
        $joined = strtolower(implode(' ', $links));
        $parts = [];
        if (str_contains($joined, 'contact')) {
            $parts[] = 'the same address book';
        }
        if (str_contains($joined, 'invoic')) {
            $parts[] = 'billing in one click';
        }
        if (str_contains($joined, 'appointment')) {
            $parts[] = 'the shared calendar';
        }

        return $parts ? ': '.implode(', ', $parts) : '';
    }

    /** @return list<string> */
    protected function professionsFor(string $moduleKey): array
    {
        $this->professions ??= Profession::query()->orderBy('sort_order')->get();

        return $this->professions
            ->filter(fn (Profession $p) => in_array($moduleKey, $p->module_keys ?? [], true))
            ->pluck('name')->take(3)->map(fn ($n) => strtolower(str_replace(' / ', ', ', $n)))->values()->all();
    }

    protected function suiteAudience(Suite $suite): string
    {
        return match ($suite->key) {
            'core' => 'every business, whatever it does',
            'finance' => 'bookkeeping, money and the professionals who manage it',
            'sales' => 'selling, quoting and keeping customers',
            'marketing' => 'reaching customers and keeping them engaged',
            'operations' => 'stock, purchasing and the daily running of a business',
            'hr' => 'managing people, pay and leave',
            'projects' => 'delivering work on time and on budget',
            'healthcare' => 'clinics, hospitals, pharmacies and practitioners',
            'education' => 'schools, colleges and trainers',
            'hospitality' => 'hotels, lodges, restaurants and bars',
            'property' => 'landlords, agents and property managers',
            'construction' => 'builders, contractors and site teams',
            'events' => 'organisers, churches, clubs and community groups',
            'services' => 'salons, gyms, workshops and professional practices',
            'retail' => 'shops, supermarkets and anyone who sells over a counter',
            'agriculture' => 'farmers, agro-dealers and cooperatives',
            'transport' => 'fleets, drivers and logistics operators',
            'utilities' => 'water, power and service providers who bill by meter',
            'government' => 'councils, agencies and public services',
            'industrial' => 'factories, workshops and plants',
            'personal' => 'running your own life as well as your business',
            default => strtolower($suite->name),
        };
    }

    /** @param  list<string>  $items */
    protected function bulletList(array $items): string
    {
        return implode("\n", array_map(fn ($i) => '• '.$i, $items));
    }

    protected function tag(string $name): string
    {
        $name = preg_replace('/\(.*?\)/', '', $name) ?? $name;
        $name = preg_replace('/\b(and|the|for|of|a|an)\b/i', ' ', $name) ?? $name;

        return Str::studly(preg_replace('/[^A-Za-z0-9 ]/', ' ', $name) ?? $name) ?: 'Zonseob';
    }
}
