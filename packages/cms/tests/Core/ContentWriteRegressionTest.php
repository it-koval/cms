<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Event\RecordCreating;
use Itk\Cms\Event\RecordPublished;
use Itk\Cms\Event\RecordPublishing;
use Itk\Cms\Tests\Core\Fixture\ResourceKernelTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ContentWriteRegressionTest extends ResourceKernelTestCase
{
    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->suffix = \bin2hex(\random_bytes(6));
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        foreach ($this->pageIds as $id) {
            $db->executeStatement('UPDATE itk_page SET parent_id = NULL WHERE id = ?', [$id]);
        }
        $em->clear();
        parent::tearDown();
    }

    #[Test]
    public function aRejectedCollectionWriteLeavesTheManagerUsable(): void
    {
        self::getContainer()->get('event_dispatcher')->addListener(RecordCreating::class, static function (RecordCreating $e): void {
            if ('test_trips' === $e->resourceKey && 'Reject' === ($e->data['title'] ?? '')) {
                throw ContentException::invalid('Rejected test write');
            }
        });
        $this->trip('First');
        try {
            $this->trip('Reject');
            self::fail('Rejected write was accepted');
        } catch (ContentException $e) {
            self::assertSame('Rejected test write', $e->getMessage());
        }
        self::assertTrue(self::getContainer()->get(EntityManagerInterface::class)->isOpen());
        $this->trip('Last');
        self::assertSame(['First', 'Last'], \array_column($this->collections->list('test_trips'), 'title'));
    }

    #[Test]
    public function republishHooksReceiveTheLiveSnapshotAndBeforeMayReject(): void
    {
        $p = $this->page('default', 'Live', '/test-republish-' . $this->suffix);
        $this->pages->publish($p['id']);
        $this->pages->saveDraft($p['id'], 'ru', ['title' => 'Draft']);
        $this->pages->unpublish($p['id']);
        $events = self::getContainer()->get('event_dispatcher');
        $deny = static function (RecordPublishing $e) use ($p): void {
            if ($e->record->id === $p['id']) {
                throw ContentException::invalid('Rejected publish');
            }
        };
        $events->addListener(RecordPublishing::class, $deny);
        try {
            $this->pages->republish($p['id']);
            self::fail('beforePublishing must run');
        } catch (ContentException $e) {
            self::assertSame('Rejected publish', $e->getMessage());
        }
        self::assertSame('draft', $this->pages->find($p['id'])->translation('ru')->status);
        $events->removeListener(RecordPublishing::class, $deny);
        $seen = [];
        foreach ([RecordPublishing::class, RecordPublished::class] as $class) {
            $events->addListener($class, static function ($e) use (&$seen, $p): void { if ($e->record->id === $p['id']) { $seen[] = $e->record->get('title'); } });
        }
        $this->pages->republish($p['id']);
        self::assertSame(['Live', 'Live'], $seen);
        self::assertSame('Draft', $this->pages->find($p['id'])->translation('ru')->draft['title']);
    }

    #[Test]
    public function pageWritesRejectEmptyTitlesAndMalformedAddressesWithoutChangingDraft(): void
    {
        $p = $this->page('default', 'Valid', '/test-validate-' . $this->suffix);
        foreach ([['title' => ' '], ['url' => ''], ['url' => "/test\tpath"], ['url' => '/test?query'], ['url' => '//test.example/path']] as $patch) {
            try {
                $this->pages->saveDraft($p['id'], 'ru', $patch);
                self::fail('Invalid patch accepted');
            } catch (ContentException $e) {
                self::assertSame(422, $e->status());
            }
            self::assertSame('Valid', $this->pages->find($p['id'])->translation('ru')->draft['title']);
            self::assertSame($p['url'], $this->pages->find($p['id'])->translation('ru')->draft['url']);
        }
        foreach (["/test\tpath", '/test?query', '//test.example/path'] as $url) {
            try {
                $bad = $this->pages->create(['title' => 'Invalid', 'url' => $url]);
                $this->pageIds[] = $bad['id'];
                self::fail('Invalid create accepted');
            } catch (ContentException $e) {
                self::assertSame(422, $e->status());
            }
        }
    }

    #[Test]
    public function createAndUpdateNormalizeSeoTheSameWay(): void
    {
        $p = $this->pages->create(['title' => 'SEO', 'url' => '/test-seo-' . $this->suffix, 'seo' => ['title' => ' Heading ', 'description' => ' Text ', 'extra' => 'ignored']]);
        $this->pageIds[] = $p['id'];
        self::assertSame(['title' => 'Heading', 'description' => 'Text'], $p['seo']);
        self::assertSame($p['seo'], $this->pages->saveDraft($p['id'], 'ru', ['seo' => ['title' => ' Heading ', 'description' => ' Text ']])['seo']);
    }

    #[Test]
    public function bulkDeleteOrdersDescendantsAndProtectsUnselectedChildren(): void
    {
        $parent = $this->page('default', 'Parent', '/test-parent-' . $this->suffix);
        $child = $this->page('default', 'Child', '/test-child-' . $this->suffix, $parent['id']);
        try {
            $this->pages->massRemove([$parent['id']]);
            self::fail('Unselected child must protect parent');
        } catch (ContentException $e) {
            self::assertSame(409, $e->status());
        }
        self::assertSame(2, $this->pages->massRemove([$parent['id'], $child['id']]));
        self::assertNull(self::getContainer()->get(EntityManagerInterface::class)->find(Page::class, $parent['id']));
    }

    #[Test]
    public function movingIntoADescendantDeeperThanFiftyIsRejectedByBothPaths(): void
    {
        $top = $this->page('default', 'Top', '/test-deep-' . $this->suffix);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $parent = $em->find(Page::class, $top['id']);
        for ($n = 0; $n < 52; ++$n) {
            $page = new Page('default', $parent);
            $t = new PageTranslation($page, 'ru');
            $t->draft = ['title' => 'Deep', 'url' => '/test-deep-' . $this->suffix . '-' . $n, 'seo' => [], 'sections' => [], 'fields' => []];
            $page->translations->set('ru', $t);
            $em->persist($page);
            $this->pageIds[] = $page->id;
            $parent = $page;
        }
        $em->flush();
        foreach (['update', 'tree'] as $method) {
            try {
                if ('update' === $method) {
                    $this->pages->update($top['id'], 'ru', ['parentId' => $parent->id]);
                } else {
                    $this->pages->tree([['id' => $top['id'], 'parentId' => $parent->id]]);
                }
                self::fail('Cycle accepted by ' . $method);
            } catch (ContentException $e) {
                self::assertSame(422, $e->status());
            }
        }
    }
}
