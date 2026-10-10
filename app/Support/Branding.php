<?php

namespace App\Support;

use App\Models\Workspace;
use App\Tenancy\WorkspaceContext;
use Illuminate\Support\Str;

/**
 * Works out the name, colour and logo the screens should show. A signed-in workspace
 * uses its own branding, falling back to its partner's when that partner is white-label;
 * on a verified custom domain (or a white-label partner's referral link) the sign-in pages
 * take that workspace's branding before anyone signs in.
 */
class Branding
{
    public const DEFAULT_COLOR = '#0073EA';

    public const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    /** @var array<string, ?Workspace> */
    protected array $hosts = [];

    public function __construct(protected WorkspaceContext $context) {}

    /**
     * @return array{name: string, color: string, logo_url: ?string, mark: string, mark_url: ?string, hide_powered_by: bool, custom: bool}
     */
    public function current(): array
    {
        $workspace = $this->context->get()
            ?? $this->workspaceForHost((string) request()?->getHost())
            ?? $this->referringPartner();

        return $this->for($workspace);
    }

    /**
     * @return array{name: string, color: string, logo_url: ?string, mark: string, mark_url: ?string, hide_powered_by: bool, custom: bool}
     */
    public function for(?Workspace $workspace): array
    {
        $partner = $workspace?->reseller?->isWhiteLabelPartner() ? $workspace->reseller : null;
        if ($workspace && ! $partner && $workspace->isWhiteLabelPartner()) {
            $partner = $workspace;
        }

        $name = $this->ownSetting($workspace, 'name') ?? $this->ownSetting($partner, 'name');
        $color = $this->ownSetting($workspace, 'color') ?? $this->ownSetting($partner, 'color');
        $logo = ($name || $color) ? ($workspace?->logo_url ?? $partner?->logo_url) : $partner?->logo_url;
        $name ??= config('app.name');
        $custom = $name !== config('app.name') || $color !== null || $logo !== null;

        return [
            'name' => $name,
            'color' => $color ?? self::DEFAULT_COLOR,
            'logo_url' => $logo,
            'mark' => Str::upper(Str::substr($name, 0, 1)),
            'mark_url' => $custom || $partner ? null : asset('images/logo-mark.png'),
            'hide_powered_by' => $partner !== null,
            'custom' => $custom,
        ];
    }

    /** The workspace whose verified custom domain is being visited, if any. */
    public function workspaceForHost(string $host): ?Workspace
    {
        $host = strtolower($host);
        if ($host === '' || $host === strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST))) {
            return null;
        }

        return $this->hosts[$host] ??= Workspace::query()
            ->where('custom_domain', $host)->whereNotNull('custom_domain_verified_at')->first();
    }

    /** CSS that repaints the main accents in the brand colour; null for the default colour. */
    public function css(string $color): ?string
    {
        if (! preg_match(self::COLOR_PATTERN, $color) || strcasecmp($color, self::DEFAULT_COLOR) === 0) {
            return null;
        }

        [$r, $g, $b] = $this->rgb($color);
        $dark = $this->mix($color, '#000000', 0.16);
        $soft = $this->mix($color, '#FFFFFF', 0.8);
        $onColor = $this->isLight($color) ? '#1F1F1F' : '#FFFFFF';

        return ":root{--bs-primary:{$color};--bs-primary-rgb:{$r},{$g},{$b};--bs-link-color:{$color};--bs-link-color-rgb:{$r},{$g},{$b};--bs-link-hover-color:{$dark}}"
            ."a,.btn-link,.text-primary{color:{$color}}"
            ."body .btn-primary{--bs-btn-bg:{$color};--bs-btn-border-color:{$color};--bs-btn-hover-bg:{$dark};--bs-btn-hover-border-color:{$dark};--bs-btn-active-bg:{$dark};--bs-btn-active-border-color:{$dark};--bs-btn-disabled-bg:{$color};--bs-btn-disabled-border-color:{$color};color:{$onColor}!important}"
            .".z-brand-mark,.z-avatar:not(.z-avatar-soft),.z-pill-primary,.bg-primary{background-color:{$color}!important;color:{$onColor}}"
            .".z-nav-link.active,.btn-soft-primary,.nav-pills .nav-link.active,.z-avatar-soft{background-color:{$soft}!important}"
            .".form-control:focus,.form-select:focus{border-color:{$color}}"
            .".form-check-input:checked{background-color:{$color};border-color:{$color}}";
    }

    protected function ownSetting(?Workspace $workspace, string $key): ?string
    {
        $value = $workspace?->setting('branding.'.$key);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        if ($key === 'color' && ! preg_match(self::COLOR_PATTERN, $value)) {
            return null;
        }

        return trim($value);
    }

    /** A white-label partner whose referral link brought this visitor here. */
    protected function referringPartner(): ?Workspace
    {
        $code = session('partner_code');
        if (! is_string($code) || $code === '') {
            return null;
        }
        $partner = app(Partners::class)->findByCode($code);

        return $partner?->isWhiteLabelPartner() ? $partner : null;
    }

    /** @return array{0: int, 1: int, 2: int} */
    protected function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    protected function mix(string $color, string $with, float $amount): string
    {
        $a = $this->rgb($color);
        $b = $this->rgb($with);

        return sprintf('#%02X%02X%02X', ...array_map(fn ($i) => (int) round($a[$i] + ($b[$i] - $a[$i]) * $amount), [0, 1, 2]));
    }

    /** Relative luminance above 0.55 needs dark text on top. */
    protected function isLight(string $color): bool
    {
        [$r, $g, $b] = array_map(function (int $channel) {
            $c = $channel / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $this->rgb($color));

        return (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) > 0.55;
    }
}
