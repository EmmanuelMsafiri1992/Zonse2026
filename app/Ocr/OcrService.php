<?php

namespace App\Ocr;

use App\Jobs\ProcessDocumentCapture;
use App\Models\DocumentCapture;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Ocr\Providers\AzureDocumentProvider;
use App\Ocr\Providers\GoogleVisionProvider;
use App\Ocr\Providers\TestProvider;
use App\Support\Audit;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Contacts\Models\Contact;

/**
 * Document capture for a workspace. The workspace picks one OCR provider (settings key
 * ocr.provider); each upload is stored privately, read by a queued job, and its fields are
 * shown for checking before they become an expense or a contact.
 */
class OcrService
{
    public const DISK = 'local';

    /** @var array<string, OcrProvider> */
    protected array $providers = [];

    public function __construct()
    {
        foreach ([new AzureDocumentProvider, new GoogleVisionProvider, new TestProvider] as $provider) {
            $this->providers[$provider->key()] = $provider;
        }
    }

    /** @return array<string, OcrProvider> */
    public function providers(): array
    {
        return $this->providers;
    }

    public function find(?string $key): ?OcrProvider
    {
        return $key ? ($this->providers[$key] ?? null) : null;
    }

    /** The workspace's chosen provider, when it is fully set up. */
    public function provider(Workspace $workspace): ?OcrProvider
    {
        $provider = $this->find($workspace->setting('ocr.provider'));

        return $provider && $this->isConfigured($workspace, $provider) ? $provider : null;
    }

    public function enabled(Workspace $workspace): bool
    {
        return $this->provider($workspace) !== null;
    }

    public function isConfigured(Workspace $workspace, OcrProvider $provider): bool
    {
        $credentials = $this->credentials($workspace, $provider);
        foreach ($provider->fields() as $field => $meta) {
            if ($meta['required'] && ($credentials[$field] ?? '') === '') {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    public function credentials(Workspace $workspace, OcrProvider $provider): array
    {
        $credentials = [];
        foreach ($provider->fields() as $field => $meta) {
            $value = (string) $workspace->setting('ocr.'.$provider->key().'.'.$field, '');
            if ($meta['secret'] && $value !== '') {
                try {
                    $value = Crypt::decryptString($value);
                } catch (DecryptException) {
                    $value = '';
                }
            }
            $credentials[$field] = $value;
        }

        return $credentials;
    }

    /**
     * Choose the provider and store its credentials (blank secrets keep the saved value).
     *
     * @param  array<string, array<string, string|null>>  $values  per provider key
     */
    public function configure(Workspace $workspace, ?string $providerKey, array $values): void
    {
        $settings = $workspace->settings ?? [];
        data_set($settings, 'ocr.provider', $this->find($providerKey)?->key());

        foreach ($this->providers as $key => $provider) {
            foreach ($provider->fields() as $field => $meta) {
                $value = trim((string) ($values[$key][$field] ?? ''));
                if ($meta['secret'] && $value === '') {
                    continue;
                }
                data_set($settings, 'ocr.'.$key.'.'.$field, $meta['secret'] ? Crypt::encryptString($value) : $value);
            }
        }

        $workspace->settings = $settings;
        $workspace->save();
    }

    /** Store an upload privately and queue it for reading. */
    public function capture(Workspace $workspace, User $user, UploadedFile $file, string $type): DocumentCapture
    {
        $uuid = (string) Str::uuid();
        $mime = (string) $file->getMimeType();
        $extension = match ($mime) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $path = 'captures/'.$workspace->id.'/'.$uuid.'.'.$extension;
        Storage::disk(self::DISK)->putFileAs(dirname($path), $file, basename($path));

        $capture = DocumentCapture::create([
            'workspace_id' => $workspace->id,
            'uuid' => $uuid,
            'type' => $type,
            'file_name' => Str::limit($file->getClientOriginalName(), 200, ''),
            'file_path' => $path,
            'mime' => $mime,
            'file_size' => (int) $file->getSize(),
            'file_hash' => hash_file('sha256', $file->getRealPath()),
            'provider' => $this->provider($workspace)?->key(),
            'created_by' => $user->id,
        ]);

        ProcessDocumentCapture::dispatch($capture->id)->afterCommit();

        return $capture;
    }

    /**
     * Read a capture with the workspace's provider and fill in its fields. Permanent problems mark
     * it failed; brief outages are rethrown so the queue retries.
     *
     * @throws OcrException when the provider is briefly unavailable
     */
    public function process(DocumentCapture $capture): void
    {
        $workspace = Workspace::query()->find($capture->workspace_id);
        $provider = $workspace ? $this->provider($workspace) : null;
        if (! $provider) {
            $capture->forceFill(['status' => 'failed', 'error' => 'Document capture is not set up for this workspace.'])->save();

            return;
        }

        $contents = Storage::disk(self::DISK)->get($capture->file_path);
        if ($contents === null) {
            $capture->forceFill(['status' => 'failed', 'error' => 'The uploaded file is missing.'])->save();

            return;
        }

        try {
            $result = $provider->read($contents, $capture->mime, $capture->type, $this->credentials($workspace, $provider));
        } catch (OcrException $e) {
            if (! $e->permanent) {
                throw $e;
            }
            $capture->forceFill(['status' => 'failed', 'provider' => $provider->key(), 'error' => mb_substr($e->getMessage(), 0, 500)])->save();

            return;
        }

        $capture->forceFill([
            'status' => 'ready',
            'provider' => $provider->key(),
            'raw_text' => $result->text,
            'fields' => $this->fields($capture->type, $result, $workspace),
            'error' => trim($result->text) === '' && ! $result->fields ? 'No text was found. Check the fields by hand or try a clearer photo.' : null,
            'processed_at' => now(),
        ])->save();
    }

    /**
     * Fields the provider labelled itself win; the rest come from the text. Dates, amounts and
     * currency are tidied so the review form gets clean values.
     *
     * @return array<string, string|null>
     */
    public function fields(string $type, OcrResult $result, ?Workspace $workspace = null): array
    {
        $parsed = FieldParser::parse($result->text, $type);
        $fields = [];
        foreach (DocumentCapture::TYPES[$type]['fields'] ?? [] as $key => $meta) {
            $value = $result->fields[$key] ?? null;
            $value = $value === null || trim((string) $value) === '' ? ($parsed[$key] ?? null) : trim((string) $value);
            $fields[$key] = $this->clean($meta['type'], $value);
        }
        if (array_key_exists('currency', $fields)) {
            $fields['currency'] ??= $workspace?->currency_code;
        }
        if (array_key_exists('category', $fields) && in_array($fields['category'], [null, 'other'], true)) {
            $fields['category'] = FieldParser::category(($fields['merchant'] ?? '').' '.$result->text);
        }

        return $fields;
    }

    protected function clean(string $type, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? substr($value, 0, 10) : FieldParser::firstDate($value),
            'money' => preg_match('/\d/', $value) ? FieldParser::normaliseAmount((string) preg_replace('/[^\d.,]/', '', $value)) : null,
            'currency' => preg_match('/^[A-Za-z]{3}$/', $value) ? strtoupper($value) : null,
            'category' => in_array($value, DocumentCapture::CATEGORIES, true) ? $value : null,
            default => mb_substr($value, 0, 190),
        };
    }

    /**
     * Turn a checked receipt or invoice into an Expense, adding the supplier as a contact for invoices.
     *
     * @param  array<string, string|null>  $fields
     */
    public function saveAsExpense(DocumentCapture $capture, array $fields, User $user): Record
    {
        return DB::transaction(function () use ($capture, $fields, $user) {
            $contact = null;
            if ($capture->type === 'invoice' && ! empty($fields['merchant'])) {
                $contact = Contact::query()->where('type', 'supplier')->where('name', $fields['merchant'])->first()
                    ?? Contact::create([
                        'type' => 'supplier', 'kind' => 'company', 'name' => $fields['merchant'],
                        'tax_number' => $fields['tax_number'] ?? null, 'currency_code' => $fields['currency'] ?? null, 'created_by' => $user->id,
                    ]);
            }

            $record = Record::create([
                'blueprint' => 'expenses',
                'entity' => 'expenses',
                'title' => Str::limit(($fields['merchant'] ?? null) ?: $capture->typeLabel().' '.$capture->created_at->format('d M Y'), 180, ''),
                'status' => 'draft',
                'amount' => $fields['total'] ?? null,
                'currency' => $fields['currency'] ?? null,
                'occurs_on' => $fields['date'] ?? $capture->created_at->toDateString(),
                'due_on' => $fields['due_date'] ?? null,
                'contact_id' => $contact?->id,
                'assignee_id' => $user->id,
                'created_by' => $user->id,
                'data' => array_filter([
                    'category' => $fields['category'] ?? 'other',
                    'supplier' => $fields['merchant'] ?? null,
                    'receipt_number' => $fields['number'] ?? null,
                ]),
            ]);

            $this->finish($capture, $fields, $record, 'Saved '.$capture->typeLabel().' from '.($fields['merchant'] ?: $capture->file_name).' as expense '.$record->number);

            return $record;
        });
    }

    /**
     * Turn a checked ID document into a contact, or update the contact who already has that ID number.
     *
     * @param  array<string, string|null>  $fields
     */
    public function saveAsContact(DocumentCapture $capture, array $fields, User $user, string $contactType = 'customer'): Contact
    {
        return DB::transaction(function () use ($capture, $fields, $user, $contactType) {
            $name = trim(($fields['first_names'] ?? '').' '.($fields['surname'] ?? ''));
            $details = array_filter([
                'ID / passport number' => $fields['id_number'] ?? null,
                'Date of birth' => ! empty($fields['date_of_birth']) ? Carbon::parse($fields['date_of_birth'])->format('d M Y') : null,
                'Sex' => $fields['sex'] ?? null,
                'Nationality' => $fields['nationality'] ?? null,
            ]);
            $note = collect($details)->map(fn (string $value, string $label) => $label.': '.$value)->implode("\n");

            $existing = ! empty($fields['id_number'])
                ? Contact::query()->where('notes', 'like', '%ID / passport number: '.$fields['id_number'].'%')->first()
                : null;

            if ($existing) {
                $existing->update(['name' => $name ?: $existing->name]);
                $contact = $existing;
            } else {
                $contact = Contact::create([
                    'type' => $contactType, 'kind' => 'person', 'name' => $name ?: 'Unnamed person',
                    'notes' => $note ?: null, 'created_by' => $user->id,
                ]);
            }

            $this->finish($capture, $fields, $contact, ($existing ? 'Matched ID document to contact ' : 'Saved ID document as contact ').$contact->name);

            return $contact;
        });
    }

    /** @param  array<string, string|null>  $fields */
    protected function finish(DocumentCapture $capture, array $fields, Model $result, string $description): void
    {
        $capture->forceFill([
            'status' => 'done',
            'fields' => $fields,
            'result_type' => $result->getMorphClass(),
            'result_id' => $result->getKey(),
        ])->save();

        Audit::log('default', 'document-captured', $description, $result);
    }

    /** Remove the stored file along with the capture. */
    public function delete(DocumentCapture $capture): void
    {
        Storage::disk(self::DISK)->delete($capture->file_path);
        $capture->delete();
    }
}
