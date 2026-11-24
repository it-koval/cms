<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
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
    /** @param array<string, mixed> $options */
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

    #[Test]
    public function overviewWithoutALanguageListKeepsMainCountsAndExplicitLegacyLocaleReads(): void
    {
        self::bootKernel();
        $key = 'test_locale_counts_' . \bin2hex(\random_bytes(6));
        self::getContainer()->get(ResourceRegistry::class)->add(new class($key) extends CollectionResource {
            public function __construct(private readonly string $resourceKey)
            {
            }

            public function key(): string
            {
                return $this->resourceKey;
            }

            public function title(): string
            {
                return 'Legacy language counts';
            }

            public function fields(): iterable
            {
                yield Text::make('Title', 'title');
            }
        });
        $service = self::getContainer()->get(CollectionService::class);
        try {
            $service->create($key, ['title' => 'Main']);
            $service->create($key, ['title' => 'Legacy'], 'en');
            $main = \array_column($service->collections(), null, 'key')[$key];
            $legacy = \array_column($service->collections('en'), null, 'key')[$key];
            self::assertSame(2, $main['count']);
            self::assertSame(1, $main['unpublished']);
            self::assertSame(['ru' => ['count' => 1, 'unpublished' => 1]], $main['locales']);
            self::assertSame(1, $legacy['unpublished']);
            self::assertSame($main['locales'], $legacy['locales']);
        } finally {
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $em->createQuery('DELETE FROM Itk\Cms\Entity\CollectionItem i WHERE i.collection = :key')->setParameter('key', $key)->execute();
            $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceKey = :key')->setParameter('key', $key)->execute();
        }
    }
}
