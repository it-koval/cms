<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\SeoResolver;
use Itk\Cms\Content\SeoRuleService;
use Itk\Cms\Controller\Admin\AbstractApiController;
use Itk\Cms\Extension\Registry;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\McpToolException;
use Itk\Cms\Mcp\McpToolRegistry;
use Itk\Cms\Repository\SeoRuleRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/** Content language validation at the boundaries, without a kernel or database. */
final class ContentLocaleInputsTest extends TestCase
{
    private function locales(): ContentLocales
    {
        return new ContentLocales('ru', ['ru' => ['name' => 'Russian'], 'en' => ['name' => 'English']]);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, ?string}> */
    public static function apiLocales(): iterable
    {
        yield 'omitted remains null' => [[], [], null];
        yield 'empty remains null' => [['locale' => ''], ['locale' => 'en'], null];
        yield 'body language' => [[], ['locale' => 'en'], 'en'];
        yield 'query wins' => [['locale' => 'ru'], ['locale' => 'en'], 'ru'];
    }

    #[Test]
    #[DataProvider('apiLocales')]
    public function apiKeepsItsOptionalLanguageContract(array $query, array $body, ?string $expected): void
    {
        self::assertSame($expected, $this->api()->selectedLocale(new Request($query), $body));
    }

    #[Test]
    public function apiRejectsAnUnconfiguredLanguage(): void
    {
        $this->expectException(ContentException::class);
        $this->expectExceptionCode(422);
        $this->api()->selectedLocale(new Request(['locale' => 'de']));
    }

    #[Test]
    public function apiKeepsLegacyLanguageCodesWhenNoListWasConfigured(): void
    {
        self::assertSame('de-DE', $this->api(new ContentLocales('ru'))->selectedLocale(new Request(['locale' => 'de-DE'])));
    }

    private function api(?ContentLocales $locales = null): object
    {
        $api = new class extends AbstractApiController {
            public function selectedLocale(Request $request, array $body = []): ?string
            {
                return $this->locale($request, $body);
            }
        };
        $api->setContentLocales($locales ?? $this->locales());

        return $api;
    }

    #[Test]
    public function mcpKeepsItsOptionalLanguageContract(): void
    {
        $tool = new ContentLocaleInputTool();
        $tool->setContentLocales($this->locales());

        self::assertNull($tool->selectedLocale([]));
        self::assertNull($tool->selectedLocale(['locale' => '']));
        self::assertSame('en', $tool->selectedLocale(['locale' => 'en']));
    }

    #[Test]
    public function mcpRejectsAnUnconfiguredLanguageWithItsExistingError(): void
    {
        $tool = new ContentLocaleInputTool();
        $tool->setContentLocales($this->locales());

        $this->expectException(McpToolException::class);
        $this->expectExceptionMessage('locale must be a language code like "ru" or "en"');
        $tool->selectedLocale(['locale' => 'de']);
    }

    #[Test]
    public function manuallyCreatedMcpToolsKeepLegacyLanguageValidation(): void
    {
        $tool = new ContentLocaleInputTool();

        self::assertSame('ru', $tool->selectedLocale(['locale' => 'ru']));

        $this->expectException(McpToolException::class);
        $this->expectExceptionMessage('locale must be a language code like "ru" or "en"');
        $tool->selectedLocale(['locale' => '1']);
    }

    #[Test]
    public function extensionToolsReceiveTheConfiguredLanguageService(): void
    {
        $extensions = new Registry();
        $extensions->mcpTools(ContentLocaleInputTool::class);
        $registry = new McpToolRegistry([], $extensions, $this->locales());
        $tool = $registry->get('test_locale_input');
        self::assertInstanceOf(ContentLocaleInputTool::class, $tool);
        self::assertSame('en', $tool->selectedLocale(['locale' => 'en']));

        $this->expectException(McpToolException::class);
        $tool->selectedLocale(['locale' => 'de']);
    }

    #[Test]
    public function seoNullStillMeansEveryLanguage(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');
        $rules = $this->createMock(SeoRuleRepository::class);
        $rules->expects(self::once())->method('findSame')->with('/news', 'exact', null)->willReturn(null);
        $service = new SeoRuleService($em, $rules, new SeoResolver($rules, new RequestStack(), 'ru'), $this->locales());

        self::assertNull($service->create(['path' => '/news', 'locale' => null, 'title' => 'News'])->locale);
    }

    /** @return iterable<string, array{string|bool}> */
    public static function wrongSeoLocales(): iterable
    {
        yield 'unconfigured code' => ['de'];
        yield 'non-string value' => [false];
    }

    #[Test]
    #[DataProvider('wrongSeoLocales')]
    public function seoKeepsItsFieldErrorForAnUnusableLanguage(string|bool $locale): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');
        $rules = $this->createMock(SeoRuleRepository::class);
        $rules->expects(self::never())->method('findSame');
        $service = new SeoRuleService($em, $rules, new SeoResolver($rules, new RequestStack(), 'ru'), $this->locales());

        try {
            $service->create(['path' => '/news', 'locale' => $locale, 'title' => 'News']);
            self::fail('An unusable language was accepted.');
        } catch (ContentException $e) {
            self::assertSame(422, $e->status());
            self::assertSame(['locale' => I18n::t('content.seo_locale_unknown')], $e->details());
        }
    }
}

/** A constructor-free extension tool that uses the shared locale helper. */
final class ContentLocaleInputTool extends AbstractMcpTool
{
    public function name(): string
    {
        return 'test_locale_input';
    }

    public function description(): string
    {
        return 'Select a configured content language.';
    }

    public function inputSchema(): array
    {
        return self::schema(['locale' => self::localeProperty()]);
    }

    public function annotations(): array
    {
        return ['readOnly' => true, 'destructive' => false];
    }

    public function requiredScope(): string
    {
        return McpScopes::READ;
    }

    public function call(array $args, McpContext $context): array
    {
        return ['locale' => self::locale($args)];
    }

    public function selectedLocale(array $args): ?string
    {
        return self::locale($args);
    }
}
