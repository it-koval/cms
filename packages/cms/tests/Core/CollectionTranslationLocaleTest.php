<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\CollectionService;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Controller\Admin\AbstractApiController;
use Itk\Cms\Field\Text;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Resource\CollectionResource;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;

/** New translations require the language catalog even when legacy reads accept arbitrary codes. */
final class CollectionTranslationLocaleTest extends KernelTestCase
{
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true);
    }

    /** @return iterable<string, array{string}> */
    public static function unknownLanguages(): iterable
    {
        yield 'valid legacy code' => ['de'];
        yield 'valid regional legacy code' => ['en-US'];
        yield 'empty code' => [''];
    }

    /** @return iterable<string, array{string}> */
    public static function localeBoundaryMethods(): iterable
    {
        yield 'GET locale' => ['GET'];
        yield 'POST translation target' => ['POST'];
    }

    #[Test]
    #[DataProvider('localeBoundaryMethods')]
    public function noLocalesLongCodesKeepTheExistingHttpBoundaryError(string $method): void
    {
        $api = new class extends AbstractApiController {
            public function input(Request $request): mixed
            {
                return $request->isMethod('POST') ? $this->translationInput($request) : $this->locale($request);
            }
        };
        $api->setContentLocales(new ContentLocales('ru'));

        try {
            $api->input(Request::create('/?locale=abcdefgh-1', $method));
            self::fail('A language code exceeding the legacy length limit was accepted.');
        } catch (ContentException $error) {
            self::assertSame(422, $error->status());
            self::assertSame(I18n::t('http.no_such_language'), $error->getMessage());
        }
    }

    #[Test]
    #[DataProvider('unknownLanguages')]
    public function noLocalesConfigurationStillRejectsTargetsOutsideTheCatalog(string $locale): void
    {
        self::bootKernel();
        self::getContainer()->get(ResourceRegistry::class)->add(new class extends CollectionResource {
            public function key(): string
            {
                return 'test_translation_locale';
            }

            public function title(): string
            {
                return 'Translation locale';
            }

            public function fields(): iterable
            {
                yield Text::make('Title', 'title');
            }
        });
        $locales = self::getContainer()->get(ContentLocales::class);
        self::assertFalse($locales->isConfigured());
        self::assertSame(['ru'], $locales->codes());

        try {
            // Rejection must happen before acquiring a database lock or loading the item.
            self::getContainer()->get(CollectionService::class)->addTranslation('test_translation_locale', 'missing-item', $locale, null);
            self::fail('A target outside the configured catalog was accepted.');
        } catch (ContentException $error) {
            self::assertSame(422, $error->status());
            self::assertSame(I18n::t('content.locale_unknown', ['locale' => $locale]), $error->getMessage());
        }
    }
}
