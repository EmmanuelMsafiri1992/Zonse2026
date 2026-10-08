<?php

namespace App\Ocr\Providers;

use App\Ocr\OcrProvider;
use App\Ocr\OcrResult;
use App\Ocr\PdfText;

/**
 * Test mode: nothing leaves the server. Text-based PDFs are read directly; photos and scans get
 * realistic sample text so the review and save steps can be tried before paying for a provider.
 */
class TestProvider implements OcrProvider
{
    public function key(): string
    {
        return 'test';
    }

    public function label(): string
    {
        return 'Test mode';
    }

    public function description(): string
    {
        return 'Nothing is sent anywhere. Reads PDFs that contain real text; photos get sample text so you can try the steps.';
    }

    public function fields(): array
    {
        return [];
    }

    public function read(string $contents, string $mime, string $type, array $credentials): OcrResult
    {
        $text = $mime === 'application/pdf' ? PdfText::extract($contents) : '';

        return new OcrResult(trim($text) !== '' ? $text : self::sample($type));
    }

    /** Example text for a document of this type, dated today. */
    public static function sample(string $type): string
    {
        $today = now()->format('d/m/Y');

        return match ($type) {
            'invoice' => implode("\n", [
                'Harare Office Supplies (Pvt) Ltd',
                '14 Kwame Nkrumah Ave, Harare',
                'VAT No: 10023456',
                'TAX INVOICE',
                'Invoice No: INV-20418',
                'Invoice Date: '.$today,
                'Due Date: '.now()->addDays(30)->format('d/m/Y'),
                'A4 Paper 80gsm x 10        65.00',
                'Toner cartridge             85.00',
                'Subtotal                   130.43',
                'VAT 15%                     19.57',
                'Total Due USD              150.00',
            ]),
            'id_document' => implode("\n", [
                'REPUBLIC OF ZIMBABWE',
                'NATIONAL REGISTRATION',
                'ID NUMBER 63-1234567 X 42',
                'SURNAME MOYO',
                'FIRST NAME TENDAI GRACE',
                'DATE OF BIRTH 14/03/1990',
                'SEX F',
                'VILLAGE OF ORIGIN MUTASA',
                'CITIZENSHIP ZIMBABWEAN',
            ]),
            default => implode("\n", [
                'ZUVA PETROLEUM',
                'Samora Machel Service Station',
                'VAT No: 10098765',
                'Receipt No: 004512',
                'Date: '.$today.' 14:32',
                'Diesel 50ppm  40.00 L x 1.55',
                'Subtotal                  53.91',
                'VAT 15%                    8.09',
                'TOTAL USD                 62.00',
                'Paid: Card',
            ]),
        };
    }
}
