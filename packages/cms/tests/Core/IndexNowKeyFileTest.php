<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\IndexNow\IndexNowSite;
use Itk\Cms\Tests\Core\Fixture\IndexNowSettings;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** GET /{key}.txt: ключ сайта отдаётся, пока переключатель включён; чужой ключ, любой другой хэш и выключенный переключатель дают 404. */
final class IndexNowKeyFileTest extends WebTestCase
{
    use IndexNowSettings;

    protected function tearDown(): void
    {
        $this->restoreSettings();
        parent::tearDown();
    }

    private function key(): string
    {
        $this->setting('domain', 'keys.example.org');

        return self::getContainer()->get(IndexNowSite::class)->key();
    }

    #[Test]
    public function theKeyOfTheSiteIsAnsweredAsPlainTextWhileTheToggleIsOn(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $key = $this->key();
        $this->setting('indexNow', true);

        $client->request('GET', '/' . $key . '.txt');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');
        self::assertSame($key, $client->getResponse()->getContent());
        $client->request('HEAD', '/' . $key . '.txt');
        self::assertResponseStatusCodeSame(200);
    }

    #[Test]
    public function withTheToggleOffTheFileIsMissing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $key = $this->key();
        $this->setting('indexNow', false);

        $client->request('GET', '/' . $key . '.txt');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function anotherKeyIsMissing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->key();
        $this->setting('indexNow', true);

        $client->request('GET', '/' . \str_repeat('a', 32) . '.txt');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function withoutASiteAddressThereIsNoKey(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setting('domain', '');
        $this->setting('indexNow', true);

        $client->request('GET', '/' . \str_repeat('a', 32) . '.txt');

        self::assertResponseStatusCodeSame(404);
    }
}
