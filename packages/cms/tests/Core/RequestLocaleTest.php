<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;

/** Public forms keep their content language and only trust a same-origin page as its fallback. */
final class RequestLocaleTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $marker;

    protected static function createKernel(array $options = []): KernelInterface
    {
        $itk = ['main_locale' => 'ru'];
        if ($options['contentLocales'] ?? true) {
            $itk['locales'] = ['ru' => ['name' => 'Русский'], 'kk' => ['name' => 'Қазақша'], 'tr' => ['name' => 'Türkçe'], 'zh' => ['name' => '中文'], 'en' => ['name' => 'English']]; // i18n-ignore: configured language names
        }
        if (isset($options['adminLocales'])) {
            $itk['admin_locales'] = $options['adminLocales'];
        }

        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, framework: ['http_client' => ['enabled' => true]], itk: $itk);
    }

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->marker = 'test-request-locale-' . \bin2hex(\random_bytes(6));
    }

    protected function tearDown(): void
    {
        $db = self::getContainer()->get(Connection::class);
        $db->executeStatement("DELETE FROM itk_notification WHERE type = 'request.new' AND link IN (SELECT '/requests/' || id FROM itk_request WHERE city = ?)", [$this->marker]);
        $db->executeStatement('DELETE FROM itk_request WHERE city = ?', [$this->marker]);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function post(string $query = '', ?string $referer = null, array $fields = [], int $status = 200, string $origin = 'http://localhost'): array
    {
        $server = null === $referer ? [] : ['HTTP_REFERER' => $referer];
        $this->client->request('POST', $origin . '/request/maintenance' . $query, $fields + ['phone' => '+79000000011', 'city' => $this->marker], [], $server);
        $text = (string) $this->client->getResponse()->getContent();
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), $text);

        return (array) \json_decode($text, true, flags: \JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    private function stored(): array
    {
        return self::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT locale, source_url FROM itk_request WHERE city = ? ORDER BY id', [$this->marker]);
    }

    #[Test]
    public function explicitKazakhIsPersistedAndOverridesTheReferer(): void
    {
        $referer = 'http://localhost/en/contact?campaign=1#form';
        self::assertTrue($this->post('?locale=kk', $referer)['success']);
        self::assertSame([['locale' => 'kk', 'source_url' => $referer]], $this->stored());
    }

    public static function invalidLocales(): iterable
    {
        yield 'unknown' => ['?locale=zz'];
        yield 'empty' => ['?locale='];
        yield 'array' => ['?locale[]=kk'];
        yield 'nested array' => ['?locale[code]=kk'];
    }

    #[Test]
    #[DataProvider('invalidLocales')]
    public function invalidExplicitLocaleIsRejectedWithoutWriting(string $query): void
    {
        $out = $this->post($query, 'http://localhost/kk/contact', status: 422);
        self::assertFalse($out['success']);
        self::assertSame('Такого языка нет', $out['message']);
        self::assertSame([], $this->stored());
    }

    public static function referrers(): iterable
    {
        yield 'same origin Kazakh' => ['http://localhost/kk/contact?x=1#form', 'kk', 'http://localhost'];
        yield 'same origin Turkish' => ['http://localhost/tr/contact', 'tr', 'http://localhost'];
        yield 'same origin primary' => ['http://localhost/contact', 'ru', 'http://localhost'];
        yield 'same origin unconfigured path' => ['http://localhost/de/contact', 'ru', 'http://localhost'];
        yield 'foreign host' => ['http://foreign.example/kk/contact', 'ru', 'http://localhost'];
        yield 'foreign subdomain' => ['http://sub.localhost/kk/contact', 'ru', 'http://localhost'];
        yield 'different scheme' => ['https://localhost/kk/contact', 'ru', 'http://localhost'];
        yield 'different port' => ['http://localhost:8080/kk/contact', 'ru', 'http://localhost'];
        yield 'same nonstandard port' => ['https://localhost:8443/kk/contact', 'kk', 'https://localhost:8443'];
        yield 'missing port' => ['https://localhost/kk/contact', 'ru', 'https://localhost:8443'];
        yield 'missing referer' => [null, 'ru', 'http://localhost'];
        yield 'relative referer' => ['/kk/contact', 'ru', 'http://localhost'];
        yield 'scheme-relative referer' => ['//localhost/kk/contact', 'ru', 'http://localhost'];
        yield 'malformed referer' => ['http://localhost:bad/kk/contact', 'ru', 'http://localhost'];
    }

    #[Test]
    #[DataProvider('referrers')]
    public function onlyTheSameOriginRefererSuppliesTheLanguage(?string $referer, string $locale, string $origin): void
    {
        $this->post(referer: $referer, origin: $origin);
        self::assertSame([['locale' => $locale, 'source_url' => $referer]], $this->stored());
    }

    #[Test]
    public function aMissingRequiredFieldRespondsInKazakhAndRestoresTheInterfaceLanguage(): void
    {
        $out = $this->post('?locale=kk', fields: ['phone' => ''], status: 422);
        self::assertFalse($out['success']);
        self::assertSame('«Телефон» өрісін толтыру керек', $out['message']);
        self::assertSame(['phone' => '«Телефон» өрісін толтыру керек'], $out['errors']);
        self::assertSame([], $this->stored());
        self::assertSame('ru', I18n::locale());
    }

    #[Test]
    public function anUnavailableInterfaceLanguageKeepsThePublicLanguage(): void
    {
        $this->restart(['adminLocales' => ['ru', 'en']]);
        $out = $this->post('?locale=kk', fields: ['phone' => ''], status: 422);
        self::assertSame('Поле «Телефон» нужно заполнить', $out['message']);
        self::assertSame([], $this->stored());
        $this->post('?locale=kk');
        self::assertSame('kk', $this->stored()[0]['locale']);
    }

    #[Test]
    public function honeypotStillSucceedsBeforeLocaleValidationAndWritesNothing(): void
    {
        self::assertSame(['success' => true], $this->post('?locale[]=kk', fields: ['itk_hp' => 'robot']));
        self::assertSame([], $this->stored());
    }

    #[Test]
    public function legacyConfigurationUsesMainLocaleAndIgnoresTheQueryAndReferer(): void
    {
        $this->restart(['contentLocales' => false]);
        foreach (['?locale=kk', '?locale=de', '?locale[]=kk'] as $query) {
            $this->post($query, 'http://localhost/kk/contact');
        }
        self::assertSame(['ru', 'ru', 'ru'], \array_column($this->stored(), 'locale'));
    }

    #[Test]
    public function legacyConfigurationRetainsTheLocaleAttribute(): void
    {
        $this->restart(['contentLocales' => false]);
        self::getContainer()->get('event_dispatcher')->addListener(KernelEvents::REQUEST, static function (RequestEvent $event): void {
            $event->getRequest()->attributes->set('_locale', 'en');
        }, -10);
        $this->post('?locale=kk', 'http://localhost/kk/contact');
        self::assertSame('en', $this->stored()[0]['locale']);
    }

    #[Test]
    public function legacyConfigurationDefaultsToMainWhenTheLocaleAttributeIsAbsent(): void
    {
        $this->restart(['contentLocales' => false]);
        self::getContainer()->get('event_dispatcher')->addListener(KernelEvents::REQUEST, static function (RequestEvent $event): void {
            $event->getRequest()->attributes->remove('_locale');
        }, -10);
        $this->post('?locale=kk', 'http://localhost/kk/contact');
        self::assertSame('ru', $this->stored()[0]['locale']);
    }

    /** @param array<string, mixed> $options */
    private function restart(array $options): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient($options);
        $this->client->disableReboot();
    }
}
