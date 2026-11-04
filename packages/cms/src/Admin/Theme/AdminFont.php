<?php

declare(strict_types=1);

namespace Itk\Cms\Admin\Theme;

use Itk\Cms\Admin\AdminBuild;
use Itk\Cms\Controller\Admin\AdminAppController;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * The font of the admin (Golos Text) for the pages that do not load the built admin: sign-in, second factor, forgot password, new password.
 * The panel itself gets its @font-face from the build; these pages cannot take the build's stylesheet (it is the whole admin), so the Twig function itk_admin_font()
 * prints the same @font-face rules for the Cyrillic, the extended Cyrillic and the Latin face (the extended one carries the Kazakh letters Қ Ғ Ң Ө Ү Ұ Ә Һ), with the addresses of the files from the Vite manifest (the names carry a hash).
 * The font is served by the site itself (/bundles/itkcms/admin/assets/); nothing is requested from a foreign host. Without a build the result is empty
 * and the pages keep the system font that follows the font name in their stylesheets.
 *
 * @internal
 */
final class AdminFont implements RuntimeExtensionInterface
{
    public const FAMILY = 'Golos Text Variable';

    /** The faces the pages need and the ranges of characters they cover (the same as in @fontsource-variable/golos-text). */
    private const FACES = [
        'cyrillic-ext' => 'U+0460-052F,U+1C80-1C8A,U+20B4,U+2DE0-2DFF,U+A640-A69F,U+FE2E-FE2F',
        'cyrillic' => 'U+0301,U+0400-045F,U+0490-0491,U+04B0-04B1,U+2116',
        'latin' => 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD',
    ];

    private ?string $css = null;

    public function __construct(private readonly string $dir = AdminBuild::DIR)
    {
    }

    /** The @font-face rules (CSS, safe to print inside <style>) or an empty string while the admin is not built. */
    public function css(): string
    {
        return $this->css ??= $this->build();
    }

    private function build(): string
    {
        $manifest = AdminBuild::manifests($this->dir)[0] ?? [];
        $rules = [];
        foreach (self::FACES as $face => $range) {
            foreach ($manifest as $key => $chunk) {
                $file = \is_array($chunk) ? ($chunk['file'] ?? null) : null;
                if (\is_string($key) && \str_ends_with($key, '/golos-text-' . $face . '-wght-normal.woff2') && \is_string($file) && 1 === \preg_match('~^assets/[\w.-]+\.woff2$~', $file)) {
                    $rules[] = \sprintf("@font-face{font-family:'%s';font-style:normal;font-display:swap;font-weight:400 900;src:url(%s%s) format('woff2-variations');unicode-range:%s}", self::FAMILY, AdminAppController::ASSETS_URL, $file, $range);
                    break;
                }
            }
        }

        return \implode('', $rules);
    }
}
