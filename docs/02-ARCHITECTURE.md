# Zonseo — Architecture Blueprint

## 1. What Zonseo is

A single web platform where a workspace (a company, clinic, school, church, farm, or an individual) subscribes, answers "what do you do?", and gets the modules that fit. Everything shares one login, one contacts list, one calendar, one money ledger, one inbox and one design system.

## 2. Stack (decided)

| Layer | Choice | Why |
|-------|--------|-----|
| Language / framework | PHP 8.4, Laravel 12 | Runs on Laragon now and on cheap shared/VPS hosting later. Huge ecosystem. |
| Database | MySQL 8 (utf8mb4) | Already installed. |
| Modules | `nwidart/laravel-modules` | Each module is a self-contained folder: routes, migrations, views, lang, menu, permissions. |
| Frontend | Blade + Alpine.js + Vite | Server-rendered, fast, no SPA complexity. Alpine handles interactivity. |
| CSS | Bootstrap 5.3 + custom SCSS design system ("Zonseo UI") | Re-creates the *feel* of the Stack theme with our own layout and components. |
| Icons | Lucide (Feather successor) as inline SVG | The reference theme uses Feather. |
| Fonts | Montserrat (headings) + Open Sans (body) | Same as the reference theme. |
| Auth | Laravel Fortify (+ 2FA) | Headless auth so our own themed views are used. |
| Permissions | `spatie/laravel-permission` with teams = workspaces | Role per workspace. |
| Audit | `spatie/laravel-activitylog` | Audit trail is a core feature. |
| Files | `spatie/laravel-medialibrary` | Attachments on any record. |
| PDF | `barryvdh/laravel-dompdf` | Invoices, receipts, certificates. |
| Excel | `maatwebsite/excel` | Import/export. |
| API | Laravel Sanctum | Tokens for mobile app & integrations. |
| Queues / cache | Database driver on dev; Redis in production | Laragon has Redis available. |

## 3. Theme: what we keep from the reference (Stack / Geo POS)

We keep the **look**, not the layout or markup.

```
Body background      #F5F7FA
Surface (cards)      #FFFFFF, no border, radius 6px, shadow 0 10px 40px rgba(62,57,107,.07), 0 2px 9px rgba(62,57,107,.06)
Primary (teal)       #00B5B8   hover #00A5A8   soft #E0F6F6
Success              #16D39A   soft #E3FAF3
Info                 #2DCEE3   soft #E6F9FC
Warning              #FFA87D   soft #FFF5EF
Danger               #FF7588   soft #FFEFF1
Dark / navy          #1B2942   (sidebar, headings)
Text                 #2A2E30   muted #626E82   light #98A4B8   border #E4E7EC
Headings             Montserrat 500
Body                 Open Sans 400, 14px
Sidebar              dark navy #1B2942, white 70% text, teal active pill
Top bar              white, 1px bottom border, search in the middle
Status pills         rounded-full, soft background + strong text (paid, due, partial…)
Stat tiles           icon block on the left in solid colour, value on the right
```

Full tokens live in `resources/scss/_tokens.scss`. Dark mode is a token swap.

## 4. Multi-tenancy

- **Single database, `workspace_id` on every tenant table.** Simpler to host, back up and migrate than one DB per tenant. Can be sharded later.
- Trait `App\Tenancy\BelongsToWorkspace` adds a global scope and auto-fills `workspace_id` on create.
- `App\Tenancy\WorkspaceContext` (singleton) holds the current workspace; set by middleware from the session; switchable from the top bar.
- Branches / locations are a child table of workspace; records that are branch-specific carry `branch_id`.

## 5. Module system

```
Modules/
  Core/            platform-level: workspaces, users, roles, plans, billing, settings, notifications, menu, catalogue
  Contacts/        people & organisations (customers, suppliers, patients, students, members all extend Party)
  Invoicing/       quotes, invoices, credit notes, receipts, recurring
  Accounting/      chart of accounts, journals, ledger, reports
  Appointments/    booking engine: services, staff, resources, availability, public booking page
  Tasks/           tasks, boards, projects
  Helpdesk/        tickets, SLAs, canned replies
  Inventory/       items, stock, warehouses, movements
  Pos/             point of sale screens on top of Inventory + Invoicing
  Hr/              employees, leave, attendance, payroll
  Clinic/          patients, visits, prescriptions (uses Contacts + Appointments + Invoicing)
  School/          students, classes, fees (uses Contacts + Invoicing)
  Events/          events, tickets, check-in
  ...
```

Each module has `module.json`:

```json
{
  "name": "Appointments",
  "alias": "appointments",
  "suite": "services",
  "catalogue_ref": "13.1",
  "title": "Appointments & Booking",
  "description": "Services, staff, resources, availability and a public booking page.",
  "icon": "calendar-check",
  "depends": ["contacts"],
  "pricing": {"monthly": 5, "yearly": 50},
  "professions": ["doctor", "salon", "consultant", "tutor"],
  "providers": ["Modules\\Appointments\\Providers\\AppointmentsServiceProvider"]
}
```

Rules:
- A module registers its **menu**, **permissions**, **settings**, **dashboard widgets**, **notification types** and **workflow triggers/actions** through the Core registries in its service provider.
- A module never touches another module's tables directly. It uses the other module's service class or events.
- Everything tenant-facing is behind `module:<alias>` middleware, which checks that the workspace has the module enabled on its plan.

## 6. Shared engines (the trick that keeps 300 modules sane)

Most industry modules are configurations of a few engines:

| Engine | Used by |
|--------|---------|
| **Party** (Contacts) | customer, supplier, patient, student, parent, member, donor, tenant, employee, farmer, driver |
| **Document** (Invoicing) | quote, invoice, credit note, receipt, bill, purchase order, fee statement, rent invoice, claim |
| **Booking** (Appointments) | doctor visit, salon slot, room, vehicle, court, table, tour seat, class |
| **Case** (Helpdesk) | support ticket, legal matter, maintenance request, complaint, incident, grievance, social-work case |
| **Item** (Inventory) | product, drug, part, book, asset, menu item, crop input |
| **Ledger** (Accounting) | every money movement from every module posts here |
| **Person-record** (Hr/Clinic/School) | employee, patient, student share timeline, documents, custom fields |
| **Form** (Core) | custom fields, intake forms, surveys, inspections, consent forms |
| **Schedule** (Core) | rosters, timetables, recurring bookings, reminders |

Industry modules add vocabulary, specific fields, workflows, reports and screens on top. So "Dental practice" = Clinic + a tooth chart + dental procedure catalogue + lab-work tracking, not a new system.

### 6.1 Blueprint engine (data-driven apps)

The long tail of the catalogue (clinic, farm, gym, school, fleet, church…) runs on one engine instead of one module each. An app is a PHP array in `app/Blueprints/definitions/<group>.php`. It is keyed by its catalogue module key and lists the app's entities: a farm has fields, plantings and harvests; a clinic has patients, visits and prescriptions.

```php
'clinic' => ['Clinic & patients', 'stethoscope', 'Description…', [
    'visits' => ['Visit', 'Reason for visit', 'waiting,in_consultation,completed,cancelled', [
        'patient:record=patients|Patient*',   // key:type=options|Label, * = required
        'temperature:number|Temperature (°C)',
    ], ['prefix' => 'VIS-', 'date' => 'Visit date', 'amount' => 'Consultation fee', 'assignee' => true, 'list' => ['patient']]],
]],
```

- **Field types:** text, textarea, number, money, date, datetime, time, select, checkbox, email, phone, url, `record=<entity>` (a link to another entity in the same app) and user (a workspace member).
- **Entity extras:** `icon`, `plural`, `prefix`, `contact` (label for a linked Contact), `amount`, `date`, `due`, `assignee`, `list` (list columns) and `description`.
- **Storage:** every record lives in one `records` table (`App\Models\Record`). The shared fields are real columns: title, status, contact, assignee, amount, dates, branch and number. Custom fields go in the `data` JSON. Numbers come from `Sequence` under the key `rec.<app>.<entity>`.
- **Screens:** generic list, form, detail and app home under `/apps/{blueprint}/{entity}` (`App\Http\Controllers\Apps`, views in `resources/views/apps`). The detail page shows child records that link back to it through `record` fields, plus notes.
- **Coverage:** every non-core catalogue entry now has an app: 6 code modules plus 298 blueprints, grouped by suite (finance, sales, marketing, logistics, people, collaboration, healthcare, education, hospitality, property, construction, events, services, retail, agriculture, transport, utilities, government, industrial, personal…). Apps that sound like integrations (payment gateways, live chat, video meetings, GPS, ISP/RADIUS, scales, SMS/email sending, website builder) are record-keeping apps. They track the work but do not connect to outside systems yet.
- **Wiring:** `BlueprintServiceProvider` adds a menu item, a dashboard widget, global search and the catalogue's "open app" link for every app. `ModuleRegistry::installedKeys()` counts blueprint keys as installed, so `php artisan zonseo:sync-modules` (or the seeder) makes them enableable. The sync also copies each blueprint's icon onto its catalogue card. Routes are gated by the `blueprint` middleware, which applies the same plan and enablement rules as `module:<key>`.
- **Scale:** a workspace may enable all ~300 apps. Module checks come from one query per request (`Workspace::hasModule` memoises the enabled keys). The dashboard shows widgets only for the 6 most recently active apps, with batched counts. The sidebar uses `App\Support\Icon::svg()` (memoised SVG) instead of the `<x-icon>` component. `BlueprintAppsTest::test_pages_stay_light_when_every_app_is_enabled` guards this.
- **App logic:** an app can add behaviour through an optional fifth element: `['depends' => ['contacts', 'invoicing'], 'logic' => ClinicLogic::class]`.
  - `depends` is merged into the catalogue, so enabling the app also enables Contacts and Invoicing.
  - The logic class extends `App\Blueprints\AppLogic` and lives in `app/Blueprints/Logic`. Its hooks:
    - `saving`/`saved` (the salon prices visits and works out commission; rentals keep unit occupancy right);
    - `validate` (one live lease per unit);
    - `recordCards`/`homeCards` (the clinic's allergy alerts and waiting room);
    - `actions`/`runAction` (school "bill class for a term", rentals "bill rent", POS "close shift");
    - `documents`/`document` (church giving statement, POS receipt);
    - `reports` (shown at `/apps/{app}/reports`);
    - `daily` (monthly rent and escalation).
  - `php artisan zonseo:run-app-schedules` runs `daily` and is scheduled at 02:00.
  - Apps with logic so far: clinic, school, pos, tenants, salon, church. Each has a test in `AppWorkflowsTest`.
- **Billing records:** the entity extra `'bill' => true`, or a map from invoice status to record status plus `'via' => 'patient'`, makes a record billable.
  - The record page then shows its invoices, a "Create invoice" button and a payment form.
  - `App\Blueprints\RecordBilling` raises the invoice. The contact is the record's own, or the `via` record's. If neither has one, a new contact is created and linked back.
  - Payments update the record through `AppLogic::invoiceChanged`; for example, school fees move to part paid, then paid.
  - Invoices carry `record_id`, plus `period` for recurring bills so a month is never billed twice. Only one open invoice per record is allowed.
  - The POS till (`/apps/pos/till`, `PosController`) uses the same path: it rings up Invoicing items, decrements stock, raises the invoice and records a full payment in one transaction.
- **Graduating:** when an app needs real workflows (stock, ledgers, scheduling), build it as a code module under `Modules/` that provides the same key. Then remove it from the definitions, because blueprint and module keys must not overlap.

## 7. Subscriptions & plans

- `plans` (Free, Starter, Business, Enterprise, plus industry bundles like "Clinic", "School").
- `plan_module` pivot: which modules a plan includes. Add-on modules can be bought individually.
- `subscriptions`: workspace → plan, status, trial ends, renews at, gateway, limits (users, storage, branches).
- Gateways are drivers behind one `PaymentGateway` interface: Stripe, PayPal, Paystack, Flutterwave, Paynow (Zimbabwe), M-Pesa, manual bank transfer.
- The same billing engine the platform uses is what tenants use to bill *their* customers (module 1.17).

## 8. Onboarding ("choose what fits you")

1. Sign up → create workspace → pick country, currency, language.
2. "What best describes you?" → profession picker (grid with icons, searchable).
3. Recommended bundle from the catalogue's profession map is pre-ticked; the user can add/remove modules.
4. Plan chosen, trial starts, modules enabled, sample data optionally seeded.
5. Dashboard shows widgets from the enabled modules only.

## 9. Directory layout (application root)

```
app/                 framework-level code (tenancy, registries, base classes, support)
Modules/             one folder per module (see §5)
resources/scss/      Zonseo UI design system
resources/views/     layouts, auth, onboarding, marketing site
resources/js/        Alpine components, charts, shared JS
database/            core migrations + seeders (plans, catalogue, demo workspace)
docs/                this folder
reference/           extracted reference theme (never shipped)
```

## 10. Build order

| Phase | Deliverable |
|-------|-------------|
| 1 | Laravel app, design system, layout shell, auth (Fortify), workspaces, roles, module registry, catalogue seed, onboarding wizard, plans & subscriptions (manual gateway first) |
| 2 | Contacts, Invoicing, Accounting-lite (receipts → ledger), Appointments, Tasks, Helpdesk |
| 3 | Inventory + POS, HR (employees, leave, attendance), Payroll |
| 4 | Clinic, School, Church/NGO, Property, Events & ticketing |
| 5 | Payment gateways, SMS/WhatsApp, email marketing, workflow builder, API |
| 6 | Remaining industry modules in catalogue order of demand |

## 11. Reference material

- `reference/geopos/` is the extracted Geo POS theme and views, used **only** to study colours, spacing and feature flows. No code is copied from it; everything in `Modules/` is written fresh.
