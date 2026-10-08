<?php

namespace Modules\Invoicing\Documents;

use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;

/**
 * How the workspace's invoices and quotes look, on screen, in print and as PDF:
 * a layout, an accent colour, titles, and what extra blocks to show.
 * Stored in the workspace settings under "documents".
 */
class DocumentDesign
{
    /** @var array<string, array{label: string, description: string}> */
    public const STYLES = [
        'classic' => ['label' => 'Classic', 'description' => 'Logo and details on the left, the title in your colour on the right.'],
        'banner' => ['label' => 'Banner', 'description' => 'A band of your colour across the top with the title in white.'],
        'minimal' => ['label' => 'Minimal', 'description' => 'Black and white with thin lines. Cheap to print.'],
    ];

    public const DEFAULT_COLOR = '#0073ea';

    /**
     * @param  array{style: string, color: string, show_logo: bool, invoice_title: string, quote_title: string, show_tax_column: bool, payment_details: ?string, signature: bool, footer: ?string}  $values
     */
    public function __construct(public readonly array $values) {}

    public static function for(?Workspace $workspace): self
    {
        $stored = (array) ($workspace?->setting('documents') ?? []);

        return new self([
            'style' => isset(self::STYLES[$stored['style'] ?? null]) ? $stored['style'] : 'classic',
            'color' => self::validColor($stored['color'] ?? null) ? strtolower($stored['color']) : self::DEFAULT_COLOR,
            'show_logo' => (bool) ($stored['show_logo'] ?? true),
            'invoice_title' => filled($stored['invoice_title'] ?? null) ? $stored['invoice_title'] : 'Invoice',
            'quote_title' => filled($stored['quote_title'] ?? null) ? $stored['quote_title'] : 'Quotation',
            'show_tax_column' => (bool) ($stored['show_tax_column'] ?? true),
            'payment_details' => filled($stored['payment_details'] ?? null) ? $stored['payment_details'] : null,
            'signature' => (bool) ($stored['signature'] ?? false),
            'footer' => $workspace?->setting('invoicing.footer'),
        ]);
    }

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'style' => ['required', 'in:'.implode(',', array_keys(self::STYLES))],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'show_logo' => ['boolean'],
            'invoice_title' => ['nullable', 'string', 'max:40'],
            'quote_title' => ['nullable', 'string', 'max:40'],
            'show_tax_column' => ['boolean'],
            'payment_details' => ['nullable', 'string', 'max:1000'],
            'signature' => ['boolean'],
        ];
    }

    /** @param array<string, mixed> $data validated input */
    public static function save(Workspace $workspace, array $data): void
    {
        $workspace->putSetting('documents', [
            'style' => $data['style'],
            'color' => strtolower($data['color']),
            'show_logo' => (bool) ($data['show_logo'] ?? false),
            'invoice_title' => filled($data['invoice_title'] ?? null) ? trim($data['invoice_title']) : null,
            'quote_title' => filled($data['quote_title'] ?? null) ? trim($data['quote_title']) : null,
            'show_tax_column' => (bool) ($data['show_tax_column'] ?? false),
            'payment_details' => filled($data['payment_details'] ?? null) ? trim($data['payment_details']) : null,
            'signature' => (bool) ($data['signature'] ?? false),
        ]);
    }

    public function __get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function title(string $kind): string
    {
        return $kind === 'invoice' ? $this->values['invoice_title'] : $this->values['quote_title'];
    }

    /** A pale tint of the accent colour for soft backgrounds. */
    public function tint(float $amount = 0.9): string
    {
        [$r, $g, $b] = sscanf($this->values['color'], '#%02x%02x%02x');

        return sprintf('#%02x%02x%02x', ...array_map(fn (int $c) => (int) round($c + (255 - $c) * $amount), [$r, $g, $b]));
    }

    /**
     * The logo to show, or null. A PDF gets the image embedded, since the renderer does not
     * fetch URLs; only PNG, JPEG and GIF files under 2 MB are embedded.
     */
    public function logo(?Workspace $workspace, bool $embed = false): ?string
    {
        if (! $this->values['show_logo'] || ! $workspace?->logo_path) {
            return null;
        }
        if (! $embed) {
            return $workspace->logo_url;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($workspace->logo_path) || $disk->size($workspace->logo_path) > 2 * 1024 * 1024) {
            return null;
        }
        $mime = $disk->mimeType($workspace->logo_path);

        return in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)
            ? 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($workspace->logo_path))
            : null;
    }

    protected static function validColor(mixed $color): bool
    {
        return is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1;
    }
}
