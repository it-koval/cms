<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Admin\AdminPath;
use Itk\Cms\Content\PageService;
use Itk\Cms\Security\AdminHeadersListener;
use Itk\Cms\Security\UserManager;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** Заголовки безопасности админки: нельзя вставить во фрейм чужого сайта, тип ответа не угадывается, предпросмотр продолжает работать. */
final class AdminHeadersTest extends WebTestCase
{
    private KernelBrowser $client;
    /** @var list<string> */
    private array $pages = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        $c = self::getContainer();
        foreach ($this->pages as $id) {
            try {
                $c->get(PageService::class)->remove($id);
            } catch (\Throwable) {
            }
        }
        $em = $c->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceId IN (:ids)')->setParameter('ids', [] === $this->pages ? ['-'] : $this->pages)->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-%@example.com')->execute();
        parent::tearDown();
    }

    private function assertProtected(string $where): void
    {
        $h = $this->client->getResponse()->headers;
        self::assertSame('SAMEORIGIN', $h->get('X-Frame-Options'), $where);
        self::assertStringContainsString("frame-ancestors 'self'", (string) $h->get('Content-Security-Policy'), $where);
        self::assertSame('nosniff', $h->get('X-Content-Type-Options'), $where);
        self::assertSame('same-origin', $h->get('Referrer-Policy'), $where);
    }

    #[Test]
    public function theLoginPageAndTheApiOfAStrangerAreProtected(): void
    {
        $this->client->request('GET', '/admin/login');
        self::assertResponseIsSuccessful();
        $this->assertProtected('login');

        $this->client->request('GET', '/admin/api/me', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertContains($this->client->getResponse()->getStatusCode(), [401, 302]);
        $this->assertProtected('api, not signed in');
    }

    #[Test]
    public function theShellAndTheApiOfASignedInPersonAreProtected(): void
    {
        $user = self::getContainer()->get(UserManager::class)->create('test-headers@example.com', 'Заголовки', 'admin', 'password-for-test');
        $this->client->loginUser($user, 'itk_admin');

        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $this->assertProtected('shell');

        $this->client->request('GET', '/admin/api/me');
        self::assertResponseIsSuccessful();
        $this->assertProtected('api');

        $this->client->request('GET', '/admin/api/no-such-call');
        self::assertResponseStatusCodeSame(404);
        $this->assertProtected('api error');
    }

    #[Test]
    public function thePreviewKeepsWorkingInTheFrameOfTheAdmin(): void
    {
        $c = self::getContainer();
        $user = $c->get(UserManager::class)->create('test-headers2@example.com', 'Заголовки', 'admin', 'password-for-test');
        $c->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'itk_admin', []));
        $page = $c->get(PageService::class)->create(['template' => 'default', 'title' => 'Заголовки предпросмотра', 'url' => '/test-headers-preview']);
        $this->pages[] = $page['id'];
        $this->client->loginUser($user, 'itk_admin');

        $this->client->request('GET', '/admin/preview/' . $page['id']);

        self::assertResponseIsSuccessful();
        $this->assertProtected('preview');
        self::assertSame("frame-ancestors 'self'", $this->client->getResponse()->headers->get('Content-Security-Policy'), 'the preview keeps its own header, it is not doubled');
    }

    #[Test]
    public function aHeaderTheControllerSetItselfIsNotOverwritten(): void
    {
        $listener = new AdminHeadersListener(self::getContainer()->get(AdminPath::class));
        $request = Request::create('/admin/x');
        $response = new Response('', 200, ['X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'no-referrer']);

        $listener(new ResponseEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    #[Test]
    public function thePublicSiteIsNotTouchedByTheAdminListener(): void
    {
        $listener = new AdminHeadersListener(self::getContainer()->get(AdminPath::class));
        foreach (['/', '/blog', '/administrator', '/adminx/login'] as $path) {
            $response = new Response();
            $listener(new ResponseEvent(self::$kernel, Request::create($path), HttpKernelInterface::MAIN_REQUEST, $response));
            self::assertFalse($response->headers->has('X-Frame-Options'), $path);
        }
    }
}
