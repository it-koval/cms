<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\PageService;
use Itk\Cms\Entity\Page;
use Itk\Cms\Event\RecordUpdating;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Resource\TemplateResource;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class PagesTemplateWriteGuardTest extends KernelTestCase
{
    #[Test]
    public function draftAndListWritesAuthorizeTheReloadedCurrentAndTargetTemplate(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $pages = $c->get(PageService::class);
        $db = $c->get(Connection::class);
        $unique = \bin2hex(\random_bytes(6));
        $page = $pages->create(['template' => 'default', 'title' => 'Guard fixture', 'url' => '/f1-write-guard-' . $unique]);
        $id = $page['id'];
        try {
            foreach (['saveDraft', 'update'] as $method) {
                $db->executeStatement('UPDATE itk_page SET template = ? WHERE id = ?', ['blog', $id]);
                $seen = null;
                $guard = static function (Page $current, string $target) use (&$seen, $db): void {
                    self::assertTrue($db->isTransactionActive(), 'authorization runs inside the locked write transaction');
                    $seen = [$current->template, $target];
                    throw new AccessDeniedException('Fixture denies the locked templates');
                };
                try {
                    if ('saveDraft' === $method) {
                        $pages->saveDraft($id, null, ['template' => 'default', 'title' => 'Changed'], null, $guard);
                    } else {
                        $pages->update($id, null, ['template' => 'default', 'title' => 'Changed'], $guard);
                    }
                    self::fail('A denied write must not succeed');
                } catch (AccessDeniedException) {
                }
                self::assertSame(['blog', 'default'], $seen, 'the refreshed current and requested templates are authorized before any update hook');
                self::assertSame('blog', $db->fetchOne('SELECT template FROM itk_page WHERE id = ?', [$id]));
                self::assertSame('Guard fixture', \json_decode((string) $db->fetchOne('SELECT draft FROM itk_page_translation WHERE page_id = ?', [$id]), true)['title']);
                $db->executeStatement('UPDATE itk_page SET template = ? WHERE id = ?', ['default', $id]);
                $c->get(EntityManagerInterface::class)->clear();
            }
        } finally {
            $db->executeStatement('UPDATE itk_page SET template = ? WHERE id = ?', ['default', $id]);
            $c->get(EntityManagerInterface::class)->clear();
            $pages->remove($id);
            $db->executeStatement('DELETE FROM itk_revision WHERE resource_id = ?', [$id]);
        }
    }

    #[Test]
    public function templateKeysMustBeStringsAndTheNormalizedTargetMustBeAuthorized(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $c->get(ResourceRegistry::class)->add(new class extends TemplateResource {
            public function key(): string
            {
                return '123';
            }

            public function title(): string
            {
                return 'Numeric template fixture';
            }

            public function fields(): iterable
            {
                return [];
            }
        });
        $pages = $c->get(PageService::class);
        $unique = \bin2hex(\random_bytes(6));
        $page = $pages->create(['template' => 'default', 'title' => 'Template type fixture', 'url' => '/f1-template-type-' . $unique]);
        $events = $c->get('event_dispatcher');
        $hookCalls = 0;
        $updating = static function (RecordUpdating $event) use ($page, &$hookCalls): void {
            if ($event->record?->id === $page['id']) {
                ++$hookCalls;
                if ('Hook target fixture' === ($event->data['title'] ?? null)) {
                    $event->data['template'] = '123';
                }
            }
        };
        $events->addListener(RecordUpdating::class, $updating);
        try {
            foreach (['saveDraft', 'update'] as $method) {
                $beforeHooks = $hookCalls;
                try {
                    $pages->{$method}($page['id'], null, ['template' => 123]);
                    self::fail('A non-string template must be refused');
                } catch (ContentException $error) {
                    self::assertSame(422, $error->status());
                }
                $seen = [];
                $guard = static function (Page $current, string $target) use (&$seen): void {
                    $seen[] = [$current->template, $target];
                    if ('123' === $target) {
                        throw new AccessDeniedException('Fixture denies the target template');
                    }
                };
                try {
                    if ('saveDraft' === $method) {
                        $pages->saveDraft($page['id'], null, ['template' => '123'], null, $guard);
                    } else {
                        $pages->update($page['id'], null, ['template' => '123'], $guard);
                    }
                    self::fail('The normalized target must be authorized');
                } catch (AccessDeniedException) {
                }
                self::assertSame([['default', '123']], $seen);
                self::assertSame($beforeHooks, $hookCalls, 'invalid or denied requested templates must not run update hooks');

                $seen = [];
                try {
                    if ('saveDraft' === $method) {
                        $pages->saveDraft($page['id'], null, ['title' => 'Hook target fixture'], null, $guard);
                    } else {
                        $pages->update($page['id'], null, ['title' => 'Hook target fixture'], $guard);
                    }
                    self::fail('The template selected by the update hook must also be authorized');
                } catch (AccessDeniedException) {
                }
                self::assertSame([['default', 'default'], ['default', '123']], $seen);
                self::assertSame($beforeHooks + 1, $hookCalls);
                self::assertSame('Template type fixture', $pages->get($page['id'])['title']);
            }
            self::assertSame('default', $pages->find($page['id'])->template);
        } finally {
            $events->removeListener(RecordUpdating::class, $updating);
            $pages->remove($page['id']);
            $c->get(Connection::class)->executeStatement('DELETE FROM itk_revision WHERE resource_id = ?', [$page['id']]);
        }
    }
}
