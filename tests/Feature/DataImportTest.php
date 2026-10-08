<?php

namespace Tests\Feature;

use App\Models\ImportRun;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Import\Spreadsheet;
use App\Support\Import\Values;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Item;
use Modules\Invoicing\Models\TaxRate;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class DataImportTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    /** @return array{0: User, 1: Workspace} */
    protected function importWorkspace(array $modules = ['contacts', 'invoicing']): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules($modules, $owner);

        return [$owner, $workspace->fresh()];
    }

    protected function csvFile(string $name, array $lines): UploadedFile
    {
        $csv = implode("\r\n", array_map(fn (array $cells) => implode(',', array_map(fn ($cell) => str_contains((string) $cell, ',') ? '"'.$cell.'"' : $cell, $cells)), $lines));

        return UploadedFile::fake()->createWithContent($name, $csv);
    }

    /** Upload a file, keep the guessed mapping, preview and return the run. */
    protected function uploadAndPreview(User $owner, string $target, UploadedFile $file, array $options = []): ImportRun
    {
        $this->actingAs($owner)->post(route('settings.imports.store'), ['target' => $target, 'file' => $file])->assertSessionHasNoErrors()->assertRedirect();
        $run = ImportRun::query()->allWorkspaces()->latest('id')->firstOrFail();

        $mapping = [];
        foreach ($run->mapping as $key => $index) {
            $mapping[str_replace('.', '__', $key)] = $index;
        }
        $this->actingAs($owner)->put(route('settings.imports.update', $run), [
            'mapping' => $mapping,
            'options' => array_merge($run->options, $options),
        ])->assertSessionHasNoErrors()->assertRedirect(route('settings.imports.show', $run).'#preview');

        return $run->fresh();
    }

    public function test_quickbooks_customer_export_is_recognised_previewed_imported_and_undone(): void
    {
        [$owner, $workspace] = $this->importWorkspace();
        $existing = Contact::factory()->for($workspace)->create(['name' => 'Old Friend', 'email' => 'friend@example.com', 'type' => 'customer']);

        $this->actingAs($owner)->get(route('settings.imports.index'))->assertOk()
            ->assertSee('Start an import')->assertSee('Contacts')->assertSee('Products &amp; services', false)->assertSee('No imports yet');

        $file = $this->csvFile('qb-customers.csv', [
            ['Customer', 'Company', 'Email', 'Phone', 'Billing City', 'Billing Country', 'Open Balance'],
            ['Tendai Moyo', 'Moyo Hardware', 'tendai@example.com', '+263 77 123 4567', 'Harare', 'Zimbabwe', '120.00'],
            ['Old Friend', '', 'FRIEND@example.com', '', 'Bulawayo', 'ZW', '0'],
            ['Chipo Banda', '', 'chipo@example.com', '', 'Lusaka', 'Narnia', '0'],
            ['', '', 'nobody@example.com', '', '', '', ''],
            ['Tendai Moyo again', 'Moyo Hardware', 'tendai@example.com', '', '', '', ''],
        ]);
        $this->actingAs($owner)->post(route('settings.imports.store'), ['target' => 'contacts', 'file' => $file])
            ->assertSessionHas('flash.message', '5 rows read from qb-customers.csv. Check the columns are matched correctly.');
        $run = ImportRun::query()->allWorkspaces()->sole();
        $this->assertSame($workspace->id, $run->workspace_id);
        $this->assertSame('quickbooks', $run->source);
        $this->assertSame(0, $run->mapping['name']);
        $this->assertSame(1, $run->mapping['company_name']);
        $this->assertSame(2, $run->mapping['email']);
        $this->assertSame(4, $run->mapping['city']);
        $this->assertSame(5, $run->mapping['country']);
        $this->assertSame('customer', $run->options['default_type']);

        $this->actingAs($owner)->get(route('settings.imports.show', $run))->assertOk()
            ->assertSee('This looks like an export from')->assertSee('QuickBooks')->assertSee('Match the columns')
            ->assertSee('Tendai Moyo · Old Friend · Chipo Banda')->assertDontSee('id="preview"', false);

        $this->actingAs($owner)->post(route('settings.imports.run', $run))->assertStatus(409);
        $this->actingAs($owner)->put(route('settings.imports.update', $run), ['mapping' => ['name' => '', 'email' => 99], 'options' => ['duplicates' => 'merge', 'date_order' => 'dmy', 'default_type' => 'customer']])
            ->assertSessionHasErrors(['mapping.name' => 'Choose the column that holds name.', 'mapping.email', 'options.duplicates']);

        $run = $this->uploadAndPreview($owner, 'contacts', $this->csvFile('qb-customers.csv', [
            ['Customer', 'Company', 'Email', 'Phone', 'Billing City', 'Billing Country', 'Open Balance'],
            ['Tendai Moyo', 'Moyo Hardware', 'tendai@example.com', '+263 77 123 4567', 'Harare', 'Zimbabwe', '120.00'],
            ['Old Friend', '', 'FRIEND@example.com', '', 'Bulawayo', 'ZW', '0'],
            ['Chipo Banda', '', 'chipo@example.com', '', 'Lusaka', 'Narnia', '0'],
            ['', '', 'nobody@example.com', '', '', '', ''],
            ['Tendai Moyo again', 'Moyo Hardware', 'tendai@example.com', '', '', '', ''],
        ]));
        $this->assertSame('ready', $run->status);
        $this->assertSame(['create' => 2, 'update' => 0, 'skip' => 2, 'error' => 1, 'warning' => 1], $run->summary['counts']);
        $this->assertSame(0, Contact::query()->forWorkspace($workspace->id)->where('email', 'tendai@example.com')->count(), 'The preview saves nothing');

        $this->actingAs($owner)->get(route('settings.imports.show', $run))->assertOk()
            ->assertSee('id="preview"', false)->assertSee('Already in Zonseo, so it is skipped.')->assertSee('Repeats an earlier row, so it is skipped.')
            ->assertSee('Needs a name or a company name.')->assertSee('Narnia')->assertSee('Import 2 rows');

        $this->actingAs($owner)->post(route('settings.imports.run', $run))
            ->assertRedirect(route('settings.imports.show', $run))
            ->assertSessionHas('flash.message', 'Import finished: 2 added, 2 skipped, 1 not imported because of problems.');

        $tendai = Contact::query()->forWorkspace($workspace->id)->where('email', 'tendai@example.com')->sole();
        $this->assertSame('Tendai Moyo', $tendai->name);
        $this->assertSame('Moyo Hardware', $tendai->company_name);
        $this->assertSame('company', $tendai->kind);
        $this->assertSame('customer', $tendai->type);
        $this->assertSame('Harare', $tendai->city);
        $this->assertSame('ZW', $tendai->country_code);
        $this->assertSame('Old Friend', $existing->fresh()->name);
        $this->assertSame(3, Contact::query()->forWorkspace($workspace->id)->count());

        $run->refresh();
        $this->assertSame('done', $run->status);
        $this->assertNull($run->rows);
        $this->assertSame([2, 0, 2, 1], [$run->created_count, $run->updated_count, $run->skipped_count, $run->failed_count]);
        $this->assertCount(2, $run->created_ids);
        $this->assertDatabaseHas('activity_log', ['workspace_id' => $workspace->id, 'log_name' => 'data', 'event' => 'imported']);

        $this->actingAs($owner)->get(route('settings.imports.show', $run))->assertOk()->assertSee('Rows to check')->assertSee('Undo import');
        $this->actingAs($owner)->get(route('settings.imports.index'))->assertOk()->assertSee('qb-customers.csv')->assertSee('QuickBooks export');
        $this->actingAs($owner)->post(route('settings.imports.run', $run))->assertStatus(409);
        $this->actingAs($owner)->delete(route('settings.imports.destroy', $run))->assertStatus(409);

        Invoice::factory()->create(['workspace_id' => $workspace->id, 'contact_id' => $tendai->id]);
        $this->actingAs($owner)->post(route('settings.imports.undo', $run))
            ->assertSessionHas('flash.message', 'Import undone: 1 record removed. 1 kept because they are already in use.');
        $this->assertModelExists($tendai);
        $this->assertSame(0, Contact::query()->forWorkspace($workspace->id)->where('email', 'chipo@example.com')->count());
        $this->assertSame('undone', $run->fresh()->status);
        $this->assertDatabaseHas('activity_log', ['workspace_id' => $workspace->id, 'log_name' => 'data', 'event' => 'import-undone']);
        $this->actingAs($owner)->post(route('settings.imports.undo', $run))->assertStatus(409);
    }

    public function test_items_import_from_an_excel_template_with_tax_rates_updates_and_barcode_checks(): void
    {
        [$owner, $workspace] = $this->importWorkspace();
        $rate = TaxRate::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Zero rated', 'rate' => 0]);
        $vat = TaxRate::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Standard VAT', 'rate' => 14.5]);
        $cement = Item::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Cement 50kg', 'sku' => 'CEM-50', 'price' => 10, 'barcode' => null]);
        Item::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Taken', 'sku' => 'TAKEN', 'barcode' => '4006381333931']);

        $template = $this->actingAs($owner)->get(route('settings.imports.template', ['items', 'xlsx']))->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="products-services-import-template.xlsx"');
        $path = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($path, $template->getContent());
        $read = Spreadsheet::read($path, 'xlsx');
        $this->assertSame(['Name', 'Price', 'SKU / code', 'Barcode', 'Type', 'Description', 'Unit', 'Cost', 'Quantity in stock', 'Reorder level', 'Tax rate', 'Active'], $read[0]['cells']);
        $this->assertSame('Cement 50kg', $read[1]['cells'][0]);
        $this->actingAs($owner)->get(route('settings.imports.template', ['items', 'csv']))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $xlsx = Spreadsheet::xlsx(['Name', 'Price', 'SKU / code', 'Barcode', 'Type', 'Cost', 'Quantity in stock', 'Tax rate', 'Active'], [
            ['Cement 50kg', '12.50', 'cem-50', '', 'Product', '9.80', '40', '14.5%', 'yes'],
            ['Delivery', '$1,250.00', 'DEL', '', 'Service', '', '', 'Zero rated', ''],
            ['River sand', 'cheap', 'SAND', '', '', '', '', '', ''],
            ['Bricks', '0.40', 'BRK', '4006381333932', '', '', '', '', ''],
            ['Tiles', '20', 'TIL', '4006381333931', 'Widget', '', '', 'Luxury 99%', 'no'],
        ], 'Items');
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, $xlsx);
        $file = new UploadedFile($path, 'price-list.xlsx', null, null, true);

        $run = $this->uploadAndPreview($owner, 'items', $file, ['duplicates' => 'update']);
        $this->assertSame(['create' => 1, 'update' => 1, 'skip' => 0, 'error' => 3, 'warning' => 0], $run->summary['counts']);
        $messages = collect($run->summary['problems'])->keyBy('line')->map(fn ($problem) => implode(' ', $problem['errors']));
        $this->assertSame('Price "cheap" is not a number.', $messages[4]);
        $this->assertSame('The last digit of barcode 4006381333932 does not match.', $messages[5]);
        $this->assertSame('Another item already has barcode 4006381333931.', $messages[6]);

        $this->actingAs($owner)->post(route('settings.imports.run', $run))
            ->assertSessionHas('flash.message', 'Import finished: 1 added, 1 updated, 3 not imported because of problems.')
            ->assertSessionHas('flash.type', 'warning');

        $cement->refresh();
        $this->assertSame('Cement 50kg', $cement->name);
        $this->assertEqualsWithDelta(12.5, (float) $cement->price, 0.001);
        $this->assertEqualsWithDelta(9.8, (float) $cement->cost, 0.001);
        $this->assertEqualsWithDelta(40, (float) $cement->stock_qty, 0.001);
        $this->assertSame($vat->id, $cement->tax_rate_id);
        $delivery = Item::query()->forWorkspace($workspace->id)->where('sku', 'DEL')->sole();
        $this->assertSame('service', $delivery->type);
        $this->assertEqualsWithDelta(1250, (float) $delivery->price, 0.001);
        $this->assertSame($rate->id, $delivery->tax_rate_id);
        $this->assertTrue((bool) $delivery->is_active);

        $this->actingAs($owner)->post(route('settings.imports.undo', $run->fresh()))
            ->assertSessionHas('flash.message', 'Import undone: 1 record removed. Records the import updated keep their new values.');
        $this->assertModelMissing($delivery);
        $this->assertEqualsWithDelta(12.5, (float) $cement->fresh()->price, 0.001);
    }

    public function test_app_records_import_links_contacts_and_related_records(): void
    {
        [$owner, $workspace] = $this->importWorkspace(['clinic', 'contacts']);
        $guardian = Contact::factory()->for($workspace)->create(['name' => 'Rudo Guardian', 'email' => 'rudo@example.com']);

        $this->actingAs($owner)->get(route('settings.imports.index', ['target' => 'records.clinic.patients']))->assertOk()
            ->assertSee('Clinic &amp; patients: Patients', false)->assertSee('value="records.clinic.patients" selected', false);

        $patients = $this->uploadAndPreview($owner, 'records.clinic.patients', $this->csvFile('patients.csv', [
            ['Full name', 'Status', 'Contact', 'Date of birth', 'Sex', 'Medical aid'],
            ['Farai Chikore', 'Active', 'rudo@example.com', '31/01/1990', 'F', 'PSMAS'],
            ['Blessing Ncube', 'inactive', '', '1985-06-15', 'male', ''],
            ['Nyasha Dube', 'retired', 'Unknown Person', 'not a date', '', ''],
        ]));
        $this->assertSame(2, $patients->summary['counts']['create']);
        $this->assertSame(1, $patients->summary['counts']['error']);
        $this->actingAs($owner)->post(route('settings.imports.run', $patients))->assertSessionHasNoErrors();

        $farai = Record::query()->ofEntity('clinic', 'patients')->where('title', 'Farai Chikore')->sole();
        $this->assertSame($workspace->id, $farai->workspace_id);
        $this->assertSame('active', $farai->status);
        $this->assertSame($guardian->id, $farai->contact_id);
        $this->assertSame('1990-01-31', $farai->data['date_of_birth']);
        $this->assertSame('PSMAS', $farai->data['medical_aid']);
        $this->assertSame('inactive', Record::query()->ofEntity('clinic', 'patients')->where('title', 'Blessing Ncube')->sole()->status);

        $visits = $this->uploadAndPreview($owner, 'records.clinic.visits', $this->csvFile('visits.csv', [
            ['Reason for visit', 'Patient', 'Temperature'],
            ['Headache', 'Farai Chikore', '37.2'],
            ['Check-up', 'Nobody Known', '36.6'],
            ['Cough', '', ''],
        ]));
        $this->assertSame(['create' => 1, 'update' => 0, 'skip' => 0, 'error' => 2, 'warning' => 0], $visits->summary['counts']);
        $this->actingAs($owner)->post(route('settings.imports.run', $visits))->assertSessionHasNoErrors();
        $visit = Record::query()->ofEntity('clinic', 'visits')->sole();
        $this->assertSame('Headache', $visit->title);
        $this->assertSame($farai->id, (int) $visit->data['patient']);
    }

    public function test_access_files_and_workspaces_are_guarded(): void
    {
        [$owner, $workspace] = $this->importWorkspace();
        $member = $this->memberOf($workspace);
        $this->actingAs($owner)->get(route('contacts.index'))->assertOk()->assertSee(route('settings.imports.index', ['target' => 'contacts']), false);
        $this->actingAs($owner)->get(route('items.index'))->assertOk()->assertSee(route('settings.imports.index', ['target' => 'items']), false);
        $this->actingAs($member)->get(route('contacts.index'))->assertOk()->assertDontSee(route('settings.imports.index', ['target' => 'contacts']), false);
        $this->actingAs($member)->get(route('settings.imports.index'))->assertForbidden();
        $this->actingAs($member)->post(route('settings.imports.store'), ['target' => 'contacts', 'file' => $this->csvFile('a.csv', [['Name'], ['A']])])->assertForbidden();

        $this->actingAs($owner)->post(route('settings.imports.store'), ['target' => 'contacts', 'file' => UploadedFile::fake()->create('old.xls', 10)])
            ->assertSessionHasErrors(['file' => 'Upload a CSV or Excel (.xlsx) file. Older .xls files need saving as .xlsx first.']);
        $this->actingAs($owner)->post(route('settings.imports.store'), ['target' => 'contacts', 'file' => $this->csvFile('empty.csv', [['Name', 'Email']])])
            ->assertSessionHasErrors(['file' => 'The file needs a heading row and at least one row of data.']);
        $this->actingAs($owner)->post(route('settings.imports.store'), ['target' => 'records.clinic.patients', 'file' => $this->csvFile('a.csv', [['Name'], ['A']])])
            ->assertSessionHasErrors(['target' => 'Choose what to import.']);
        $broken = UploadedFile::fake()->createWithContent('broken.xlsx', 'not a zip');
        $this->actingAs($owner)->post(route('settings.imports.store'), ['target' => 'contacts', 'file' => $broken])->assertSessionHasErrors('file');
        $this->actingAs($owner)->get(route('settings.imports.template', ['records.clinic.patients', 'csv']))->assertNotFound();
        $this->assertSame(0, ImportRun::query()->allWorkspaces()->count());

        $this->actingAs($owner)->post(route('settings.imports.store'), ['target' => 'contacts', 'file' => $this->csvFile('mine.csv', [['Name'], ['A']])])->assertSessionHasNoErrors();
        $mine = ImportRun::query()->allWorkspaces()->sole();
        $this->actingAs($owner)->delete(route('settings.imports.destroy', $mine))
            ->assertRedirect(route('settings.imports.index'))->assertSessionHas('flash.message', 'Import of mine.csv cancelled.');
        $this->assertModelMissing($mine);

        [$otherOwner, $otherWorkspace] = $this->importWorkspace();
        $foreign = ImportRun::factory()->create(['workspace_id' => $otherWorkspace->id, 'user_id' => $otherOwner->id]);
        $this->actingAs($owner)->get(route('settings.imports.show', $foreign))->assertNotFound();
        $this->actingAs($owner)->delete(route('settings.imports.destroy', $foreign))->assertNotFound();
        $this->assertModelExists($foreign);
    }

    public function test_values_and_spreadsheets_are_read_the_way_people_write_them(): void
    {
        $this->assertSame(1234.5, Values::number('$1,234.50'));
        $this->assertSame(1234.5, Values::number('1.234,50'));
        $this->assertSame(-20.0, Values::number('(20.00)'));
        $this->assertNull(Values::number('abc'));
        $this->assertSame('2026-03-04', Values::date('04/03/2026', 'dmy'));
        $this->assertSame('2026-04-03', Values::date('04/03/2026', 'mdy'));
        $this->assertSame('2026-01-31', Values::date('46053', 'dmy'));
        $this->assertTrue(Values::boolean('Yes'));
        $this->assertFalse(Values::boolean('inactive'));
        $this->assertSame('ZW', Values::country('Zimbabwe'));

        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, "\xEF\xBB\xBFName;Amount\r\nCaf\xC3\xA9 One;12,50\r\n;\r\nTwo;3\r\n");
        $rows = Spreadsheet::read($path, 'csv');
        $this->assertSame([['line' => 1, 'cells' => ['Name', 'Amount']], ['line' => 2, 'cells' => ['Café One', '12,50']], ['line' => 4, 'cells' => ['Two', '3']]], $rows);

        file_put_contents($path, "Name,City\nJos\xE9,S\xE3o Paulo\n");
        $this->assertSame(['José', 'São Paulo'], Spreadsheet::read($path, 'csv')[1]['cells']);
    }
}
