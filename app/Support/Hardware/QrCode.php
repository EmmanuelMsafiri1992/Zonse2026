<?php

namespace App\Support\Hardware;

use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** QR codes drawn as inline SVG (the library ships with Fortify for two-factor sign-in). */
class QrCode
{
    public static function svg(string $data, int $size = 120): string
    {
        $style = new RendererStyle($size, 1, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(0, 0, 0)));
        $svg = (new Writer(new ImageRenderer($style, new SvgImageBackEnd)))->writeString($data);

        return trim(substr($svg, strpos($svg, "\n") + 1));
    }
}
