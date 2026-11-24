<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class TwigFunctionNamesTest extends KernelTestCase
{
    #[Test]
    public function renamedFunctionsRenderThroughTheBundleRuntimeWithoutLegacyAliases(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get(Environment::class);
        // Symfony's render_* wildcard resolves render_sections without registering a CMS alias.
        $registered = $twig->getFunctions();
        foreach (['menu', 'settings', 'listing', 'media_url', 'media_srcset', 'media_width', 'media_ratio', 'page_url', 'render_sections'] as $name) {
            self::assertNotNull($twig->getFunction('itk_' . $name), 'the prefixed function is registered: ' . $name);
            self::assertArrayNotHasKey($name, $registered, 'the old name is not registered as an alias: ' . $name);
        }

        $rendered = $twig->createTemplate(<<<'TWIG'
{{ {
    menu: itk_menu('__test_missing_twig_area'),
    settings: itk_settings('__test_missing_twig_setting', 'Fallback'),
    listing: itk_listing(page),
    mediaUrl: itk_media_url(image, 640),
    mediaSrcset: itk_media_srcset(image, 640),
    mediaWidth: itk_media_width('https://example.com/image.jpg'),
    mediaRatio: itk_media_ratio(700, 250),
    pageUrl: itk_page_url({kind: 'url', url: '/contact?from=twig#form'}),
    sections: itk_render_sections(page)|trim
}|json_encode|raw }}
TWIG)->render([
            'image' => ['src' => '/uploads/media/2026/11/twig-names.jpg', 'alt' => 'Example'],
            'page' => ['template' => 'default', 'sections' => [
                ['id' => 'twig-section', 'type' => 'html', 'data' => ['html' => '<strong>Rendered section</strong>']],
            ]],
        ]);

        self::assertSame([
            'menu' => [],
            'settings' => 'Fallback',
            'listing' => [],
            'mediaUrl' => '/uploads/media/_v/w640/2026/11/twig-names.webp',
            'mediaSrcset' => '/uploads/media/_v/w320/2026/11/twig-names.webp 320w, /uploads/media/_v/w640/2026/11/twig-names.webp 640w',
            'mediaWidth' => null,
            'mediaRatio' => '14:5',
            'pageUrl' => '/contact?from=twig#form',
            'sections' => '<strong>Rendered section</strong>',
        ], \json_decode($rendered, true, 512, \JSON_THROW_ON_ERROR));

        self::assertSame('<strong>Rendered section</strong>', \trim($twig->createTemplate('{{ itk_render_sections(page) }}')->render([
            'page' => ['sections' => [['id' => 'safe-section', 'type' => 'html', 'data' => ['html' => '<strong>Rendered section</strong>']]]],
        ])), 'section HTML remains safe after the rename');
    }
}
