<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\PageService;
use Itk\Cms\Entity\ApiToken;
use Itk\Cms\Entity\Request as SiteRequest;
use Itk\Cms\Entity\User;
use Itk\Cms\Mcp\ApiTokenService;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpContextFactory;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\McpServer;
use Itk\Cms\Mcp\McpSettings;
use Itk\Cms\Media\MediaService;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Security\UserManager;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Helpers of the MCP tests: fictional people (test-mcp-…@example.com), their keys, direct calls of tools, and the cleanup of everything the tests make.
 * The switch «ИИ-ассистенты» is put back as it was.
 */
trait McpSupport
{
    private bool $mcpWasOn = false;
    /** @var list<string> */
    private array $mcpPageIds = [];

    protected function mcpBoot(): void
    {
        $c = self::getContainer();
        $registry = $c->get(ResourceRegistry::class);
        $registry->add(new TripsCollection());
        $registry->add(new TestTemplate());
        $registry->add(new FlatTemplate());
        $registry->add(new TestSettings());
        $this->mcpWasOn = $c->get(McpSettings::class)->isEnabled();
        $c->get(McpSettings::class)->setEnabled(true);
        TripsCollection::$log = [];
        TestTemplate::$log = [];
    }

    protected function mcpClean(): void
    {
        $c = self::getContainer();
        $c->get('security.token_storage')->setToken(new UsernamePasswordToken(new User('test-clean@example.com', 'clean', 'admin'), 'itk_admin', []));
        $em = $c->get(EntityManagerInterface::class);
        $em->clear();
        $db = $c->get(Connection::class);
        $ids = $db->fetchFirstColumn("SELECT page_id FROM itk_page_translation WHERE draft ->> 'title' LIKE 'TestMcp%'");
        $ids = \array_values(\array_unique([...$ids, ...$this->mcpPageIds]));
        // a page with children cannot be removed: what is refused waits for the next round, after its children are gone (the order of the list is not the order of the tree)
        for ($pending = $ids; [] !== $pending;) {
            $left = [];
            foreach ($pending as $id) {
                try {
                    $c->get(PageService::class)->remove((string) $id);
                } catch (\Throwable) {
                    $left[] = $id;
                }
            }
            $pending = \count($left) < \count($pending) ? $left : [];
        }
        $this->mcpPageIds = [];
        $em->clear();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\CollectionItem i WHERE i.collection = :c')->setParameter('c', 'test_trips')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceKey LIKE :k OR r.resourceId IN (:ids)')->setParameter('k', 'test%')->setParameter('ids', [] === $ids ? ['-'] : $ids)->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Request r WHERE r.name LIKE :n')->setParameter('n', 'TestMcp%')->execute();
        foreach ($db->fetchFirstColumn("SELECT id FROM itk_media_file WHERE original_name LIKE 'test-mcp-%'") as $mid) {
            try {
                $c->get(MediaService::class)->remove((string) $mid);
            } catch (\Throwable) {
            }
        }
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-mcp-%@example.com')->execute(); // the keys go with them
        $c->get(McpSettings::class)->setEnabled($this->mcpWasOn);
        $c->get('security.token_storage')->setToken(null);
    }

    protected function mcpUser(string $role = 'admin', string $tag = ''): User
    {
        $email = 'test-mcp-' . $role . ('' === $tag ? '' : '-' . $tag) . '-' . \bin2hex(\random_bytes(3)) . '@example.com';

        return self::getContainer()->get(UserManager::class)->create($email, 'TestMcp ' . $role, $role, 'password-for-test');
    }

    /**
     * @param list<string> $scopes
     *
     * @return array{token: ApiToken, plain: string}
     */
    protected function mcpKey(User $user, array $scopes = [McpScopes::READ], ?int $days = 30, string $name = 'TestMcp key'): array
    {
        // a request resets the entity manager of a test kernel: the person is taken again from the database
        $user = self::getContainer()->get(EntityManagerInterface::class)->find(User::class, $user->id) ?? $user;

        return self::getContainer()->get(ApiTokenService::class)->create($user, $name, $scopes, $days);
    }

    /** A key with scopes the owner's role does not offer (made straight in the database): the rights of the person must still decide. @param list<string> $scopes */
    protected function mcpForcedKey(User $user, array $scopes, string $name = 'TestMcp forced'): array
    {
        $made = $this->mcpKey($user, [McpScopes::READ], 30, $name);
        $made['token']->scopes = McpScopes::normalize($scopes);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $made;
    }

    protected function mcpContext(ApiToken $token): McpContext
    {
        return self::getContainer()->get(McpContextFactory::class)->signIn($token);
    }

    /**
     * Calls a tool in-process as the owner of the key.
     *
     * @param array<string, mixed> $args
     *
     * @return array{ok: bool, data: ?array<string, mixed>, text: string, rpc: ?array<string, mixed>}
     */
    protected function mcpCall(McpContext $ctx, string $tool, array $args = []): array
    {
        if (!$ctx->isSignedInAsOwner()) {
            $this->mcpContext($ctx->token); // another person was signed in meanwhile
        }
        $raw = self::getContainer()->get(McpServer::class)->handle((string) \json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $args]]), $ctx);
        $reply = (array) \json_decode((string) $raw, true);
        if (isset($reply['error'])) {
            return ['ok' => false, 'data' => null, 'text' => (string) $reply['error']['message'], 'rpc' => $reply['error']];
        }
        $text = (string) ($reply['result']['content'][0]['text'] ?? '');

        return ['ok' => !$reply['result']['isError'], 'data' => $reply['result']['isError'] ? null : (array) \json_decode($text, true), 'text' => $text, 'rpc' => null];
    }

    /** @return list<string> the names of the tools the key is offered */
    protected function mcpToolNames(McpContext $ctx): array
    {
        $reply = (array) \json_decode((string) self::getContainer()->get(McpServer::class)->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list"}', $ctx), true);

        return \array_column($reply['result']['tools'], 'name');
    }

    /** A page the tests clean up afterwards. @return array<string, mixed> */
    protected function mcpPage(McpContext $ctx, string $title = 'TestMcp page', ?string $url = null, array $more = []): array
    {
        if (\in_array($more['template'] ?? null, ['test_tpl', 'test_flat'], true) && !isset($more['parent_id'])) {
            $more['parent_id'] = $this->mcpPage($ctx, 'TestMcp Parent')['id']; // the test template stands only inside an ordinary page
        }
        $r = $this->mcpCall($ctx, 'page_create', ['title' => $title, 'url' => $url ?? '/test-mcp-' . \bin2hex(\random_bytes(3))] + $more);
        self::assertTrue($r['ok'], $r['text']);
        $this->mcpPageIds[] = $r['data']['page']['id'];

        return $r['data']['page'];
    }

    protected function mcpRequest(string $name = 'TestMcp Client', string $phone = '+7 (000) 000-00-00'): SiteRequest
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $r = new SiteRequest('call');
        $r->name = $name;
        $r->phone = $phone;
        $r->city = 'Тестоград';
        $r->data = ['name' => $name, 'phone' => $phone, 'comment' => 'Хочу брекеты'];
        $em->persist($r);
        $em->flush();

        return $r;
    }
}
