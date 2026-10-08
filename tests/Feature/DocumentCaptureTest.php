<?php

namespace Tests\Feature;

use App\Models\DocumentCapture;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Ocr\FieldParser;
use App\Ocr\OcrService;
use App\Ocr\Providers\TestProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Modules\Contacts\Models\Contact;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** Scanning receipts, invoices and ID documents, checking the fields, and saving them as expenses or contacts. */
class DocumentCaptureTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    /** @return array{0: User, 1: Workspace} */
    protected function workspace(?string $provider = 'test', array $credentials = []): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['expenses', 'contacts'], $owner);
        if ($provider) {
            app(OcrService::class)->configure($workspace, $provider, [$provider => $credentials]);
        }

        return [$owner, $workspace->fresh()];
    }

    protected function upload(User $user, string $type, UploadedFile ...$files): DocumentCapture
    {
        $this->actingAs($user)->post(route('captures.store'), ['type' => $type, 'files' => $files])->assertSessionHasNoErrors();

        return DocumentCapture::allWorkspaces()->latest('id')->firstOrFail();
    }

    protected function textPdf(string ...$lines): string
    {
        $content = "BT /F1 10 Tf 40 800 Td\n";
        foreach ($lines as $line) {
            $content .= '('.addcslashes($line, '()\\').") Tj 0 -14 Td\n";
        }
        $content .= 'ET';

        return "%PDF-1.4\n1 0 obj << /Length ".strlen($content)." >>\nstream\n".$content."\nendstream\nendobj\ntrailer << >>\n%%EOF";
    }

    public function test_receipt_text_is_turned_into_fields(): void
    {
        $fields = FieldParser::parse(TestProvider::sample('receipt'), 'receipt');

        $this->assertSame('Zuva Petroleum', $fields['merchant']);
        $this->assertSame(now()->toDateString(), $fields['date']);
        $this->assertSame('62.00', $fields['total']);
        $this->assertSame('8.09', $fields['tax']);
        $this->assertSame('USD', $fields['currency']);
        $this->assertSame('004512', $fields['number']);
        $this->assertSame('10098765', $fields['tax_number']);
        $this->assertSame('fuel', $fields['category']);
    }

    public function test_invoice_and_id_text_is_turned_into_fields(): void
    {
        $invoice = FieldParser::parse(TestProvider::sample('invoice'), 'invoice');
        $this->assertSame('Harare Office Supplies (Pvt) Ltd', $invoice['merchant']);
        $this->assertSame('INV-20418', $invoice['number']);
        $this->assertSame(now()->toDateString(), $invoice['date']);
        $this->assertSame(now()->addDays(30)->toDateString(), $invoice['due_date']);
        $this->assertSame('150.00', $invoice['total']);
        $this->assertSame('19.57', $invoice['tax']);
        $this->assertSame('office', $invoice['category']);

        $id = FieldParser::parse(TestProvider::sample('id_document'), 'id_document');
        $this->assertSame(['surname' => 'Moyo', 'first_names' => 'Tendai Grace', 'id_number' => '63-1234567X42', 'date_of_birth' => '1990-03-14', 'sex' => 'Female', 'nationality' => 'Zimbabwean'], $id);

        $passport = FieldParser::parse("PASSPORT\nP<ZWEBANDA<<RUDO<CHIPO<<<<<<<<<<<<<<<<<<<<<<\nFN12345674ZWE8507223F3001012<<<<<<<<<<<<<<04", 'id_document');
        $this->assertSame(['surname' => 'Banda', 'first_names' => 'Rudo Chipo', 'id_number' => 'FN1234567', 'date_of_birth' => '1985-07-22', 'sex' => 'Female', 'nationality' => 'ZWE'], $passport);
    }

    public function test_dates_amounts_and_totals_in_other_layouts(): void
    {
        $this->assertSame('2026-03-12', FieldParser::firstDate('Printed 12 March 2026 at 10:04'));
        $this->assertSame('2026-03-12', FieldParser::firstDate('Mar 12, 2026'));
        $this->assertSame('2026-03-25', FieldParser::firstDate('03/25/2026'));
        $this->assertSame('2026-01-05', FieldParser::firstDate('2026-01-05T10:00'));
        $this->assertSame('1234.56', FieldParser::normaliseAmount('1 234,56'));
        $this->assertSame('1234.56', FieldParser::normaliseAmount('1.234,56'));
        $this->assertSame('1234.56', FieldParser::normaliseAmount('1,234.56'));

        $fields = FieldParser::parse("Chicken Inn\nCASH SALE\nDate: 05-02-2026\nSubtotal R 1 200,00\nVAT 15% R 180,00\nTOTAL R 1 380,00\nCASH R 1 500,00\nCHANGE R 120,00", 'receipt');
        $this->assertSame('Chicken Inn', $fields['merchant']);
        $this->assertSame('2026-02-05', $fields['date']);
        $this->assertSame('1380.00', $fields['total']);
        $this->assertSame('180.00', $fields['tax']);
        $this->assertSame('ZAR', $fields['currency']);
        $this->assertSame('meals', $fields['category']);
    }

    public function test_a_receipt_photo_is_scanned_checked_and_saved_as_an_expense(): void
    {
        [$owner, $workspace] = $this->workspace();

        $capture = $this->upload($owner, 'receipt', UploadedFile::fake()->image('slip.jpg', 400, 800));

        $this->assertSame('ready', $capture->status);
        $this->assertSame('test', $capture->provider);
        $this->assertSame('62.00', $capture->field('total'));
        Storage::disk('local')->assertExists($capture->file_path);
        $this->assertStringStartsWith('captures/'.$workspace->id.'/', $capture->file_path);

        $this->actingAs($owner)->get(route('captures.show', $capture))->assertOk()->assertSee('Check the details')->assertSee('Zuva Petroleum');
        $this->actingAs($owner)->get(route('captures.file', $capture))->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');

        $fields = $capture->fields;
        $fields['total'] = '61.50';
        $response = $this->actingAs($owner)->put(route('captures.update', $capture), ['action' => 'expense', 'fields' => $fields]);

        $record = Record::allWorkspaces()->where('blueprint', 'expenses')->firstOrFail();
        $response->assertRedirect($record->url());
        $this->assertSame('Zuva Petroleum', $record->title);
        $this->assertSame('61.50', $record->amount);
        $this->assertSame('USD', $record->currency);
        $this->assertSame(now()->toDateString(), $record->occurs_on->toDateString());
        $this->assertSame(['category' => 'fuel', 'supplier' => 'Zuva Petroleum', 'receipt_number' => '004512'], $record->data);
        $this->assertSame($workspace->id, $record->workspace_id);

        $capture->refresh();
        $this->assertSame('done', $capture->status);
        $this->assertTrue($capture->result->is($record));
        $this->assertDatabaseHas('activity_log', ['event' => 'document-captured', 'subject_id' => $record->id]);

        $this->actingAs($owner)->put(route('captures.update', $capture), ['action' => 'expense', 'fields' => $fields])->assertForbidden();
        $this->assertSame(1, Record::allWorkspaces()->where('blueprint', 'expenses')->count());
    }

    public function test_a_supplier_invoice_pdf_is_read_from_its_text_and_adds_the_supplier(): void
    {
        [$owner] = $this->workspace();
        $pdf = $this->textPdf('Kudu Hardware', 'VAT No: 20045678', 'Invoice No: KH-7781', 'Invoice Date: 03/02/2026', 'Due Date: 05/03/2026', 'Cement 10 bags 120.00', 'VAT 15% 18.00', 'Amount Due USD 138.00');

        $capture = $this->upload($owner, 'invoice', UploadedFile::fake()->createWithContent('bill.pdf', $pdf));

        $this->assertSame('ready', $capture->status);
        $this->assertStringContainsString('Kudu Hardware', $capture->raw_text);
        $this->assertSame(['merchant' => 'Kudu Hardware', 'number' => 'KH-7781', 'date' => '2026-02-03', 'due_date' => '2026-03-05', 'total' => '138.00', 'tax' => '18.00', 'currency' => 'USD', 'tax_number' => '20045678', 'category' => 'repairs'], $capture->fields);

        $this->actingAs($owner)->put(route('captures.update', $capture), ['action' => 'expense', 'fields' => $capture->fields])->assertSessionHasNoErrors();

        $supplier = Contact::allWorkspaces()->where('name', 'Kudu Hardware')->firstOrFail();
        $this->assertSame('supplier', $supplier->type);
        $this->assertSame('20045678', $supplier->tax_number);
        $record = Record::allWorkspaces()->where('blueprint', 'expenses')->firstOrFail();
        $this->assertSame($supplier->id, $record->contact_id);
        $this->assertSame('2026-03-05', $record->due_on->toDateString());
    }

    public function test_an_id_document_becomes_a_contact_and_a_second_scan_matches_it(): void
    {
        [$owner] = $this->workspace();

        $first = $this->upload($owner, 'id_document', UploadedFile::fake()->image('id-front.png', 600, 400));
        $this->actingAs($owner)->put(route('captures.update', $first), ['action' => 'contact', 'contact_type' => 'lead', 'fields' => $first->fields])->assertSessionHasNoErrors();

        $contact = Contact::allWorkspaces()->where('name', 'Tendai Grace Moyo')->firstOrFail();
        $this->assertSame('lead', $contact->type);
        $this->assertSame('person', $contact->kind);
        $this->assertStringContainsString('ID / passport number: 63-1234567X42', $contact->notes);
        $this->assertStringContainsString('Date of birth: 14 Mar 1990', $contact->notes);

        $second = $this->upload($owner, 'id_document', UploadedFile::fake()->image('id-again.png', 600, 401));
        $this->actingAs($owner)->put(route('captures.update', $second), ['action' => 'contact', 'fields' => $second->fields]);

        $this->assertSame(1, Contact::allWorkspaces()->where('kind', 'person')->count());
        $this->assertTrue($second->fresh()->result->is($contact));
    }

    public function test_corrections_are_validated_and_saved_without_creating_anything(): void
    {
        [$owner] = $this->workspace();
        $capture = $this->upload($owner, 'receipt', UploadedFile::fake()->image('slip.jpg'));

        $this->actingAs($owner)->put(route('captures.update', $capture), ['action' => 'save', 'fields' => ['total' => 'lots', 'date' => 'soon', 'category' => 'yachts']])
            ->assertSessionHasErrors(['fields.total', 'fields.date', 'fields.category']);

        $this->actingAs($owner)->put(route('captures.update', $capture), ['action' => 'expense', 'fields' => ['merchant' => 'Spar']])
            ->assertSessionHasErrors('fields.total');

        $this->actingAs($owner)->put(route('captures.update', $capture), ['action' => 'save', 'fields' => ['merchant' => 'Spar Avondale', 'total' => '9.5', 'currency' => 'zwg']])
            ->assertSessionHasNoErrors();
        $capture->refresh();
        $this->assertSame('ready', $capture->status);
        $this->assertSame('Spar Avondale', $capture->field('merchant'));
        $this->assertSame('9.50', $capture->field('total'));
        $this->assertSame('ZWG', $capture->field('currency'));
        $this->assertSame(0, Record::allWorkspaces()->count());

        $this->actingAs($owner)->put(route('captures.update', $capture), ['action' => 'contact', 'fields' => []])->assertForbidden();
    }

    public function test_the_same_file_is_not_scanned_twice_and_bad_files_are_refused(): void
    {
        [$owner] = $this->workspace();
        $photo = UploadedFile::fake()->image('slip.jpg');
        $this->upload($owner, 'receipt', $photo);

        $this->actingAs($owner)->post(route('captures.store'), ['type' => 'receipt', 'files' => [$photo]])
            ->assertSessionHas('flash', fn (array $flash) => str_contains($flash['message'], 'already been uploaded'));
        $this->assertSame(1, DocumentCapture::allWorkspaces()->count());

        $this->actingAs($owner)->post(route('captures.store'), ['type' => 'receipt', 'files' => [UploadedFile::fake()->createWithContent('evil.html', '<script>alert(1)</script>')]])
            ->assertSessionHasErrors('files.0');
        $this->actingAs($owner)->post(route('captures.store'), ['type' => 'passport-photo', 'files' => [UploadedFile::fake()->image('a.jpg')]])
            ->assertSessionHasErrors('type');
        $this->actingAs($owner)->post(route('captures.store'), ['type' => 'receipt', 'files' => array_map(fn ($i) => UploadedFile::fake()->image($i.'.jpg', 10 + $i, 10), range(1, 11))])
            ->assertSessionHasErrors('files');
    }

    public function test_scanning_needs_a_provider_and_settings_store_keys_encrypted(): void
    {
        [$owner, $workspace] = $this->workspace(provider: null);

        $this->actingAs($owner)->get(route('captures.index'))->assertOk()->assertSee('Not set up yet');
        $this->actingAs($owner)->post(route('captures.store'), ['type' => 'receipt', 'files' => [UploadedFile::fake()->image('a.jpg')]])
            ->assertSessionHas('flash', fn (array $flash) => $flash['type'] === 'warning');
        $this->assertSame(0, DocumentCapture::allWorkspaces()->count());

        $this->actingAs($owner)->put(route('settings.ocr.update'), ['provider' => 'google_vision'])
            ->assertSessionHas('flash', fn (array $flash) => $flash['type'] === 'warning');
        $this->actingAs($owner)->put(route('settings.ocr.update'), ['provider' => 'google_vision', 'google_vision' => ['api_key' => 'AIza-secret-1234']])
            ->assertSessionHas('flash', fn (array $flash) => $flash['type'] === 'success');

        $stored = $workspace->fresh()->setting('ocr.google_vision.api_key');
        $this->assertNotSame('AIza-secret-1234', $stored);
        $this->assertSame('AIza-secret-1234', Crypt::decryptString($stored));
        $this->actingAs($owner)->get(route('settings.ocr.edit'))->assertOk()->assertSee('••••1234')->assertDontSee('AIza-secret-1234');

        $member = $this->memberOf($workspace);
        $this->actingAs($member)->get(route('settings.ocr.edit'))->assertForbidden();
    }

    public function test_google_vision_reads_photos_and_its_errors_are_shown(): void
    {
        [$owner] = $this->workspace('google_vision', ['api_key' => 'AIza-test']);
        Http::fake(['vision.googleapis.com/v1/images:annotate' => Http::sequence()
            ->push(['responses' => [['fullTextAnnotation' => ['text' => "PICK N PAY\nDate 07/02/2026\nTOTAL USD 23.40"]]]])
            ->push(['error' => ['message' => 'API key not valid.']], 400),
        ]);

        $capture = $this->upload($owner, 'receipt', UploadedFile::fake()->image('a.jpg'));
        $this->assertSame('ready', $capture->status);
        $this->assertSame(['Pick N Pay', '2026-02-07', '23.40'], [$capture->field('merchant'), $capture->field('date'), $capture->field('total')]);
        Http::assertSent(fn ($request) => $request->hasHeader('X-Goog-Api-Key', 'AIza-test') && $request['requests'][0]['features'][0]['type'] === 'DOCUMENT_TEXT_DETECTION');

        $failed = $this->upload($owner, 'receipt', UploadedFile::fake()->image('b.jpg', 20, 20));
        $this->assertSame('failed', $failed->status);
        $this->assertSame('Google Cloud Vision: API key not valid.', $failed->error);
        $this->actingAs($owner)->get(route('captures.show', $failed))->assertSee('could not be read')->assertSee('Try again');

        app(OcrService::class)->configure($failed->workspace()->first(), 'test', []);
        $this->actingAs($owner)->post(route('captures.retry', $failed));
        $this->assertSame('ready', $failed->fresh()->status);
    }

    public function test_azure_returns_labelled_fields_after_polling(): void
    {
        Sleep::fake();
        [$owner] = $this->workspace('azure_document', ['endpoint' => 'https://zonseo.cognitiveservices.azure.com/', 'api_key' => 'az-key']);
        $operation = 'https://zonseo.cognitiveservices.azure.com/documentintelligence/documentModels/prebuilt-receipt/analyzeResults/abc';
        Http::fake([
            'zonseo.cognitiveservices.azure.com/documentintelligence/documentModels/prebuilt-receipt:analyze*' => Http::response('', 202, ['Operation-Location' => $operation]),
            $operation => Http::sequence()
                ->push(['status' => 'running'])
                ->push(['status' => 'succeeded', 'analyzeResult' => [
                    'content' => "SAMPLE TEXT\nTOTAL 44.10",
                    'documents' => [['fields' => [
                        'MerchantName' => ['valueString' => 'Food Lovers Market'],
                        'TransactionDate' => ['valueDate' => '2026-02-01'],
                        'Total' => ['valueCurrency' => ['amount' => 45.1, 'currencyCode' => 'USD']],
                        'TotalTax' => ['valueCurrency' => ['amount' => 5.88]],
                    ]]],
                ]]),
        ]);

        $capture = $this->upload($owner, 'receipt', UploadedFile::fake()->image('a.jpg'));

        $this->assertSame('ready', $capture->status);
        $this->assertSame(['Food Lovers Market', '2026-02-01', '45.10', '5.88', 'USD', 'meals'], [
            $capture->field('merchant'), $capture->field('date'), $capture->field('total'), $capture->field('tax'), $capture->field('currency'), $capture->field('category'),
        ]);
        Http::assertSent(fn ($request) => $request->hasHeader('Ocp-Apim-Subscription-Key', 'az-key'));
        Sleep::assertSleptTimes(2);

        [$other] = $this->workspace('azure_document', ['endpoint' => 'https://evil.example.com', 'api_key' => 'x']);
        $refused = $this->upload($other, 'receipt', UploadedFile::fake()->image('b.jpg', 30, 30));
        $this->assertSame('failed', $refused->status);
        $this->assertStringContainsString('cognitiveservices.azure.com', $refused->error);
    }

    public function test_members_see_only_their_own_scans_and_viewers_cannot_scan(): void
    {
        [$owner, $workspace] = $this->workspace();
        $member = $this->memberOf($workspace);
        $colleague = $this->memberOf($workspace);
        $manager = $this->memberOf($workspace, 'manager');
        $viewer = $this->memberOf($workspace, 'viewer');

        $capture = $this->upload($member, 'id_document', UploadedFile::fake()->image('id.png'));

        $this->actingAs($member)->get(route('captures.index'))->assertOk()->assertSee('Tendai Grace Moyo');
        $this->actingAs($colleague)->get(route('captures.index'))->assertOk()->assertDontSee('Tendai Grace Moyo');
        $this->actingAs($colleague)->get(route('captures.show', $capture))->assertForbidden();
        $this->actingAs($colleague)->get(route('captures.file', $capture))->assertForbidden();
        $this->actingAs($manager)->get(route('captures.show', $capture))->assertOk();
        $this->actingAs($owner)->get(route('captures.show', $capture))->assertOk();

        $this->actingAs($viewer)->get(route('captures.index'))->assertForbidden();
        $this->actingAs($viewer)->post(route('captures.store'), ['type' => 'receipt', 'files' => [UploadedFile::fake()->image('v.jpg')]])->assertForbidden();

        [$stranger] = $this->workspace();
        $this->actingAs($stranger)->get(route('captures.show', $capture))->assertNotFound();

        $this->actingAs($member)->delete(route('captures.destroy', $capture))->assertRedirect(route('captures.index'));
        $this->assertSame(0, DocumentCapture::allWorkspaces()->count());
        Storage::disk('local')->assertMissing($capture->file_path);
    }
}
