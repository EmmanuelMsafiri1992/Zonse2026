<?php

namespace App\Social;

use GdImage;
use Illuminate\Support\Str;

/**
 * Draws the 1200×1200 branded card that goes with each post: the suite as an eyebrow, the title,
 * up to five bullet points, and the Zonseob mark with a call to action along the bottom.
 * Uses GD with the DejaVu fonts bundled with dompdf, so it needs nothing installed on the server.
 */
class PostCard
{
    public const SIZE = 1200;

    protected const TEAL = [0, 181, 184];

    protected const WHITE = [255, 255, 255];

    protected const MUTED = [176, 186, 204];

    public function available(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagettftext')
            && is_file($this->font(true)) && is_file($this->font(false));
    }

    /** Renders the card and returns the PNG path, or null when GD or the fonts are missing. */
    public function render(PostDraft $draft, string $directory): ?string
    {
        if (! $this->available()) {
            return null;
        }
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $size = self::SIZE;
        $im = imagecreatetruecolor($size, $size);
        $navy = $this->backgroundColour();
        imagefill($im, 0, 0, $this->colour($im, $navy));

        $teal = $this->colour($im, self::TEAL);
        $white = $this->colour($im, self::WHITE);
        $muted = $this->colour($im, self::MUTED);

        // Accent bar down the left and a soft teal panel in the corner.
        imagefilledrectangle($im, 0, 0, 18, $size, $teal);
        $glow = imagecolorallocatealpha($im, self::TEAL[0], self::TEAL[1], self::TEAL[2], 112);
        imagefilledellipse($im, $size - 120, 140, 620, 620, $glow);

        $bold = $this->font(true);
        $regular = $this->font(false);
        $x = 96;

        // Eyebrow: the suite, or the series label for the introduction.
        imagettftext($im, 30, 0, $x, 150, $teal, $bold, Str::upper($draft->eyebrow));

        // Title, wrapped to the width and shrunk when it is long.
        $titleSize = 72;
        do {
            $titleLines = $this->wrap($draft->title, $bold, $titleSize, $size - $x - 96);
            $titleSize -= 4;
        } while (count($titleLines) > 3 && $titleSize > 44);
        $titleSize += 4;
        $y = 240;
        foreach ($titleLines as $line) {
            $y += (int) ($titleSize * 1.25);
            imagettftext($im, $titleSize, 0, $x, $y, $white, $bold, $line);
        }

        // Thin rule, then bullets.
        $y += 56;
        imagefilledrectangle($im, $x, $y, $x + 140, $y + 5, $teal);
        $y += 50;

        $bullets = array_slice($draft->bullets, 0, 5);
        $bulletSize = count($bullets) > 4 ? 30 : 34;
        foreach ($bullets as $bullet) {
            $lines = $this->wrap($bullet, $regular, $bulletSize, $size - $x - 150);
            foreach (array_slice($lines, 0, 2) as $i => $line) {
                $y += (int) ($bulletSize * 1.55);
                if ($i === 0) {
                    imagefilledellipse($im, $x + 12, $y - (int) ($bulletSize * 0.38), 16, 16, $teal);
                }
                imagettftext($im, $bulletSize, 0, $x + 48, $y, $white, $regular, $line);
            }
            $y += 10;
            if ($y > $size - 300) {
                break;
            }
        }

        // Footer: logo mark, site and call to action.
        $footerY = $size - 150;
        imagefilledrectangle($im, $x, $footerY - 50, $size - 96, $footerY - 48, $this->colour($im, [36, 48, 94]));
        $mark = public_path('images/logo-mark.png');
        if (is_file($mark) && ($logo = @imagecreatefrompng($mark))) {
            imagecopyresampled($im, $logo, $x, $footerY - 10, 0, 0, 96, 96, imagesx($logo), imagesy($logo));
        }
        imagettftext($im, 34, 0, $x + 124, $footerY + 32, $white, $bold, 'zonseob.com');
        imagettftext($im, 26, 0, $x + 124, $footerY + 76, $muted, $regular, 'One platform, every profession. Start free.');

        $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$draft->kind.'-'.Str::slug($draft->key).'.png';
        imagepng($im, $path, 6);

        return $path;
    }

    /** @return list<string> */
    protected function wrap(string $text, string $font, int $size, int $maxWidth): array
    {
        $lines = [];
        $current = '';
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
            $try = $current === '' ? $word : $current.' '.$word;
            if ($this->width($try, $font, $size) <= $maxWidth || $current === '') {
                $current = $try;
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    protected function width(string $text, string $font, int $size): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return $box ? abs($box[2] - $box[0]) : 0;
    }

    /** @param  array{0: int, 1: int, 2: int}  $rgb */
    protected function colour(GdImage $im, array $rgb): int
    {
        return imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
    }

    /**
     * The card background matches the logo's own background exactly, so the mark sits on it
     * without a visible box. Falls back to the brand navy.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    protected function backgroundColour(): array
    {
        $mark = public_path('images/logo-mark.png');
        if (is_file($mark) && ($logo = @imagecreatefrompng($mark))) {
            $c = imagecolorat($logo, 2, 2);
            if ((($c >> 24) & 0x7F) < 60) {
                return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
            }
        }

        return [27, 41, 66];
    }

    protected function font(bool $bold): string
    {
        $dir = base_path('vendor/dompdf/dompdf/lib/fonts');

        return $dir.'/'.($bold ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf');
    }
}
