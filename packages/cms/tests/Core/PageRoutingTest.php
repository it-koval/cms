<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\PageService;
use Itk\Cms\Content\RedirectService;
use Itk\Cms\Entity\Request as RequestEntity;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Публичные маршруты ядра: страница по опубликованному адресу, редиректы, заявки с сайта, закрытая админка. */
final class PageRoutingTest extends WebTestCase
{
    private KernelBrowser $client;
    private PageService $pages;
    /** @var list<string> */
    private array $created = [];
    /** @var list<string> */
    private array $redirects = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->pages = self::getContainer()->get(PageService::class);
    }

    protected function tearDown(): void
    {
        $redirects = self::getContainer()->get(RedirectService::class);
        foreach ($this->redirects as $id) {
            try {
                $redirects->remove($id);
            } catch (\Throwable) {
            }
        }
        foreach ($this->created as $id) {
            try {
                $this->pages->remove($id);
            } catch (\Throwable) {
            }
        }
        // the redirect the core makes by itself when a published address changes
        foreach ($redirects->all() as $row) {
            if (\str_starts_with($row->fromPath, '/test-')) {
                $redirects->remove($row->id);
            }
        }
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function newPage(string $title, string $url): array
    {
        $page = $this->pages->create(['template' => 'default', 'title' => $title, 'url' => $url]);
        $this->created[] = $page['id'];

        return $page;
    }

    #[Test]
    public function aDraftIsNotOnTheSiteUntilPublished(): void
    {
        $page = $this->newPage('Тест маршрута', '/test-route-test');

        $this->client->request('GET', '/test-route-test');
        self::assertResponseStatusCodeSame(404);

        $this->pages->publish($page['id']);
        $this->client->request('GET', '/test-route-test');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Тест маршрута', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function unpublishingTakesThePageOffTheSite(): void
    {
        $page = $this->newPage('Снимаемая', '/test-unpublish');
        $this->pages->publish($page['id']);
        $this->pages->unpublish($page['id']);

        $this->client->request('GET', '/test-unpublish');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function aChangedPublishedAddressRedirectsFromTheOldOne(): void
    {
        $page = $this->newPage('Переезд', '/test-old-address');
        $this->pages->publish($page['id']);
        $this->pages->saveDraft($page['id'], null, ['url' => '/test-new-address'] + $this->pages->getDraft($page['id'])['page']);
        $this->pages->publish($page['id']);

        $this->client->request('GET', '/test-old-address');
        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects('/test-new-address', 301);

        $this->client->request('GET', '/test-new-address');
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function aManualRedirectAnswersWithItsCode(): void
    {
        $redirect = self::getContainer()->get(RedirectService::class)->create('/test-manual', '/about-us', 302);
        $this->redirects[] = $redirect->id;

        $this->client->request('GET', '/test-manual');

        self::assertResponseRedirects('/about-us', 302);
    }

    #[Test]
    public function aTrailingSlashIsRedirectedToTheCanonicalAddress(): void
    {
        $page = $this->newPage('Слэш', '/test-slash');
        $this->pages->publish($page['id']);

        $this->client->request('GET', '/test-slash/');

        self::assertResponseRedirects('/test-slash', 301);
    }

    #[Test]
    public function aFormSubmissionBecomesARequest(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $before = (int) $em->getRepository(RequestEntity::class)->count([]);

        $this->client->request('POST', '/request/call', ['phone' => '+7(900)000-00-09', 'city' => 'Тест', 'place' => 'phpunit']);
        self::assertResponseIsSuccessful();
        self::assertTrue(\json_decode((string) $this->client->getResponse()->getContent(), true)['success']);

        self::assertSame($before + 1, (int) $em->getRepository(RequestEntity::class)->count([]));
        $last = $em->getRepository(RequestEntity::class)->findOneBy([], ['id' => 'DESC']);
        self::assertSame('call', $last->form);
        self::assertSame('new', $last->work);
        $em->createQuery('DELETE FROM Itk\Cms\Entity\StoredNotification n WHERE n.title = :t')->setParameter('t', 'Новая заявка №' . $last->id)->execute(); // the bell's note of it
        $em->remove($last);
        $em->flush();
    }

    #[Test]
    public function anInvalidPhoneIsRejectedAndTheHoneypotIsSilent(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $before = (int) $em->getRepository(RequestEntity::class)->count([]);

        $this->client->request('POST', '/request/call', ['phone' => '123']);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', '/request/call', ['phone' => '+7(900)000-00-09', 'itk_hp' => 'bot']);
        self::assertResponseIsSuccessful(); // the bot is not told it was caught

        $this->client->request('POST', '/request/no-such-form', ['phone' => '+7(900)000-00-09']);
        self::assertResponseStatusCodeSame(404);

        self::assertSame($before, (int) $em->getRepository(RequestEntity::class)->count([]));
    }

    #[Test]
    public function theAdminApiNeedsALogin(): void
    {
        $this->client->request('GET', '/admin/api/me');
        self::assertResponseStatusCodeSame(401);

        $this->client->request('GET', '/admin/login');
        self::assertResponseIsSuccessful();
    }
}
