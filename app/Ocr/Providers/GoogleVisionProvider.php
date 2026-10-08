<?php

namespace App\Ocr\Providers;

use App\Ocr\OcrException;
use App\Ocr\OcrProvider;
use App\Ocr\OcrResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Google Cloud Vision document text detection. Reads any document type; fields are picked out locally. */
class GoogleVisionProvider implements OcrProvider
{
    public function key(): string
    {
        return 'google_vision';
    }

    public function label(): string
    {
        return 'Google Cloud Vision';
    }

    public function description(): string
    {
        return 'Strong on photos, handwriting and ID cards in many languages. Reads the first 5 pages of a PDF.';
    }

    public function fields(): array
    {
        return [
            'api_key' => ['label' => 'API key', 'secret' => true, 'required' => true, 'help' => 'A Google Cloud API key with the Cloud Vision API enabled.'],
        ];
    }

    public function read(string $contents, string $mime, string $type, array $credentials): OcrResult
    {
        $isPdf = $mime === 'application/pdf';
        $feature = [['type' => 'DOCUMENT_TEXT_DETECTION']];
        $request = $isPdf
            ? ['inputConfig' => ['content' => base64_encode($contents), 'mimeType' => 'application/pdf'], 'features' => $feature, 'pages' => [1, 2, 3, 4, 5]]
            : ['image' => ['content' => base64_encode($contents)], 'features' => $feature];

        try {
            $response = Http::withHeaders(['X-Goog-Api-Key' => $credentials['api_key']])->acceptJson()->timeout(60)
                ->post('https://vision.googleapis.com/v1/'.($isPdf ? 'files' : 'images').':annotate', ['requests' => [$request]]);
        } catch (ConnectionException $e) {
            throw new OcrException('Google Cloud Vision could not be reached.', false, $e);
        }

        if ($response->failed()) {
            throw new OcrException('Google Cloud Vision: '.($response->json('error.message') ?? 'HTTP '.$response->status()), $response->status() < 500 && $response->status() !== 429);
        }

        $result = $response->json('responses.0', []);
        if (isset($result['error']['message'])) {
            throw new OcrException('Google Cloud Vision: '.$result['error']['message']);
        }

        $pages = $isPdf ? array_column($result['responses'] ?? [], 'fullTextAnnotation') : [$result['fullTextAnnotation'] ?? []];

        return new OcrResult(trim(implode("\n", array_map(fn ($page) => $page['text'] ?? '', $pages))));
    }
}
