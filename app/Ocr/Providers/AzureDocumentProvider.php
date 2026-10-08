<?php

namespace App\Ocr\Providers;

use App\Ocr\OcrException;
use App\Ocr\OcrProvider;
use App\Ocr\OcrResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Azure AI Document Intelligence prebuilt models. Picks out receipt, invoice and ID fields itself;
 * anything it misses is filled in from the text.
 */
class AzureDocumentProvider implements OcrProvider
{
    protected const API_VERSION = '2024-11-30';

    protected const MODELS = ['receipt' => 'prebuilt-receipt', 'invoice' => 'prebuilt-invoice', 'id_document' => 'prebuilt-idDocument'];

    /** How many times to ask for the result before giving up and letting the queue retry. */
    protected const POLLS = 30;

    public function key(): string
    {
        return 'azure_document';
    }

    public function label(): string
    {
        return 'Azure AI Document Intelligence';
    }

    public function description(): string
    {
        return 'Trained on receipts, invoices and ID documents, so totals, dates and names come back already labelled.';
    }

    public function fields(): array
    {
        return [
            'endpoint' => ['label' => 'Endpoint', 'secret' => false, 'required' => true, 'help' => 'From the Azure portal, e.g. https://yourname.cognitiveservices.azure.com'],
            'api_key' => ['label' => 'Key', 'secret' => true, 'required' => true],
        ];
    }

    public function read(string $contents, string $mime, string $type, array $credentials): OcrResult
    {
        $host = strtolower((string) preg_replace('#^https?://#i', '', rtrim(trim($credentials['endpoint']), '/')));
        if (! preg_match('/^[a-z0-9-]+\.(cognitiveservices\.azure\.com|api\.cognitive\.microsoft\.com)$/', $host)) {
            throw new OcrException('Azure: the endpoint should look like https://yourname.cognitiveservices.azure.com.');
        }

        $client = Http::withHeaders(['Ocp-Apim-Subscription-Key' => $credentials['api_key']])->acceptJson()->timeout(30);
        $model = self::MODELS[$type] ?? 'prebuilt-read';

        try {
            $response = $client->post('https://'.$host.'/documentintelligence/documentModels/'.$model.':analyze?api-version='.self::API_VERSION, [
                'base64Source' => base64_encode($contents),
            ]);
        } catch (ConnectionException $e) {
            throw new OcrException('Azure could not be reached.', false, $e);
        }
        $this->guard($response->status(), $response->json('error.message'));

        $location = (string) $response->header('Operation-Location');
        if (! str_starts_with($location, 'https://'.$host.'/')) {
            throw new OcrException('Azure did not return a result address.', false);
        }

        return $this->result($this->poll($client, $location), $type);
    }

    /** @return array<string, mixed> */
    protected function poll(PendingRequest $client, string $location): array
    {
        for ($attempt = 0; $attempt < self::POLLS; $attempt++) {
            Sleep::for(2)->seconds();
            try {
                $response = $client->get($location);
            } catch (ConnectionException $e) {
                throw new OcrException('Azure could not be reached.', false, $e);
            }
            $this->guard($response->status(), $response->json('error.message'));

            $status = $response->json('status');
            if ($status === 'succeeded') {
                return $response->json('analyzeResult', []);
            }
            if ($status === 'failed') {
                throw new OcrException('Azure: '.($response->json('error.message') ?? 'the document could not be read.'));
            }
        }

        throw new OcrException('Azure took too long to read the document.', false);
    }

    protected function guard(int $status, ?string $message): void
    {
        if ($status >= 400) {
            throw new OcrException('Azure: '.($message ?? 'HTTP '.$status), $status < 500 && $status !== 429);
        }
    }

    /** @param  array<string, mixed>  $analysis */
    protected function result(array $analysis, string $type): OcrResult
    {
        $found = $analysis['documents'][0]['fields'] ?? [];
        $value = fn (string $name) => $this->value($found[$name] ?? null);
        $currency = fn (string $name) => $found[$name]['valueCurrency']['currencyCode'] ?? null;

        $fields = match ($type) {
            'receipt' => [
                'merchant' => $value('MerchantName'),
                'date' => $value('TransactionDate'),
                'total' => $value('Total'),
                'tax' => $value('TotalTax'),
                'currency' => $currency('Total'),
            ],
            'invoice' => [
                'merchant' => $value('VendorName'),
                'number' => $value('InvoiceId'),
                'date' => $value('InvoiceDate'),
                'due_date' => $value('DueDate'),
                'total' => $value('InvoiceTotal') ?? $value('AmountDue'),
                'tax' => $value('TotalTax'),
                'currency' => $currency('InvoiceTotal'),
                'tax_number' => $value('VendorTaxId'),
            ],
            'id_document' => [
                'surname' => $value('LastName'),
                'first_names' => $value('FirstName'),
                'id_number' => $value('DocumentNumber'),
                'date_of_birth' => $value('DateOfBirth'),
                'sex' => $value('Sex'),
                'nationality' => $value('Nationality') ?? $value('CountryRegion'),
            ],
            default => [],
        };

        return new OcrResult((string) ($analysis['content'] ?? ''), array_filter($fields, fn ($field) => $field !== null && $field !== ''));
    }

    /** @param  array<string, mixed>|null  $field */
    protected function value(?array $field): ?string
    {
        if (! $field) {
            return null;
        }
        $value = $field['valueCurrency']['amount'] ?? $field['valueNumber'] ?? $field['valueDate'] ?? $field['valueString']
            ?? $field['valueCountryRegion'] ?? $field['content'] ?? null;

        return is_float($value) || is_int($value) ? number_format((float) $value, 2, '.', '') : ($value === null ? null : trim((string) $value));
    }
}
