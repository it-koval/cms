<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\CollectionService;
use Itk\Cms\Entity\User;
use Itk\Cms\Field\Text;
use Itk\Cms\Resource\CollectionResource;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\App\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class AccountSecurityHttpTest extends WebTestCase
{
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, security: ['access_control' => []]);
    }

    public function testActionsRequireSignInAndThePermissionWithoutAccessControl(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $key = 'f1_accounts_' . \bin2hex(\random_bytes(5));
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
                return 'Action fixture';
            }

            public function fields(): iterable
            {
                yield Text::make('Title', 'title');
            }
        });
        $collections = self::getContainer()->get(CollectionService::class);
        $record = $collections->create($key, ['title' => 'Innocuous fixture']);
        $users = [];
        $em = self::getContainer()->get(EntityManagerInterface::class);
        try {
            foreach ([null, 'requests', 'admin'] as $role) {
                $client->getCookieJar()->clear();
                if (null !== $role) {
                    $user = self::getContainer()->get(UserManager::class)->create('test-f1-http-' . \bin2hex(\random_bytes(5)) . '@example.com', 'Fixture', $role, 'innocuous-test-password');
                    $users[] = $user->id;
                    $client->loginUser($user, 'itk_admin');
                }
                if (null === $role) {
                    $session = self::getContainer()->get('session.factory')->createSession();
                    $session->set('_itk_form', 1);
                    $session->save();
                    $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie($session->getName(), $session->getId()));
                    $request = \Symfony\Component\HttpFoundation\Request::create('/admin/api/resources/' . $key . '/actions/delete-selected');
                    $request->setSession($session);
                    $csrf = self::getContainer()->get(\Itk\Cms\Security\AdminCsrf::class)->token($request);
                } else {
                    $client->request('GET', '/admin');
                    \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $client->getResponse()->getContent(), $matches);
                    $csrf = $matches[1] ?? '';
                }
                self::assertNotEmpty($csrf, 'Each request has a valid session CSRF token.');
                $client->request('POST', '/admin/api/resources/' . $key . '/actions/delete-selected', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], \json_encode(['ids' => [$record['id']]]));
                self::assertResponseStatusCodeSame(null === $role ? 401 : ('admin' === $role ? 200 : 403));
                self::assertSame('admin' === $role ? 0 : 1, $em->getConnection()->fetchOne('SELECT COUNT(*) FROM itk_collection_item WHERE id = ?', [$record['id']]) * 1);
            }
        } finally {
            $em->clear();
            $em->createQuery('DELETE FROM Itk\\Cms\\Entity\\CollectionItem i WHERE i.collection = :key')->setParameter('key', $key)->execute();
            $em->createQuery('DELETE FROM Itk\\Cms\\Entity\\Revision r WHERE r.resourceKey = :key')->setParameter('key', $key)->execute();
            if ([] !== $users) {
                $em->createQuery('DELETE FROM Itk\\Cms\\Entity\\User u WHERE u.id IN (:ids)')->setParameter('ids', $users)->execute();
            }
        }
    }

    public function testBlockedUnknownAndWrongPasswordLoginsHaveTheSamePublicFailure(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = self::getContainer()->get(UserManager::class)->create('test-f1-login-' . \bin2hex(\random_bytes(5)) . '@example.com', 'Fixture', 'editor', 'innocuous-test-password');
        $user->status = User::BLOCKED;
        $em->flush();
        try {
            $failures = [];
            foreach ([[$user->email, 'innocuous-test-password'], ['absent-' . \bin2hex(\random_bytes(5)) . '@example.com', 'innocuous-test-password'], [$user->email, 'wrong-innocuous-password']] as [$email, $password]) {
                $client->getCookieJar()->clear();
                $client->request('GET', '/admin/login');
                \preg_match('/name="_csrf_token" value="([^"]+)"/', (string) $client->getResponse()->getContent(), $matches);
                self::assertNotEmpty($matches[1] ?? '');
                $client->request('POST', '/admin/login', ['_username' => $email, '_password' => $password, '_csrf_token' => $matches[1]]);
                $status = $client->getResponse()->getStatusCode();
                self::assertResponseRedirects('/admin/login');
                $client->followRedirect();
                $failures[] = [$status, $client->getResponse()->getStatusCode(), $client->getCrawler()->filter('.error')->text()];
            }
            self::assertSame($failures[0], $failures[1]);
            self::assertSame($failures[0], $failures[2]);
        } finally {
            $em->clear();
            $em->createQuery('DELETE FROM Itk\\Cms\\Entity\\User u WHERE u.id = :id')->setParameter('id', $user->id)->execute();
        }
    }

    public function testBlockingAUserEndsAccessFromTheExistingSession(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = self::getContainer()->get(UserManager::class)->create('test-f1-session-' . \bin2hex(\random_bytes(5)) . '@example.com', 'Fixture', 'admin', 'innocuous-test-password');
        try {
            $client->loginUser($user, 'itk_admin');
            $client->request('GET', '/admin/api/me');
            self::assertResponseIsSuccessful();
            $em->getConnection()->executeStatement('UPDATE itk_user SET status = ? WHERE id = ?', [User::BLOCKED, $user->id]);
            $em->clear();
            $client->request('GET', '/admin/api/me');
            $me = \json_decode((string) $client->getResponse()->getContent(), true);
            self::assertNull($me['user'] ?? null, 'The old authenticated session cannot retain its user.');
            self::assertNull(self::getContainer()->get('security.token_storage')->getToken()?->getUser());
        } finally {
            $em->clear();
            $em->createQuery('DELETE FROM Itk\\Cms\\Entity\\User u WHERE u.id = :id')->setParameter('id', $user->id)->execute();
        }
    }
}
