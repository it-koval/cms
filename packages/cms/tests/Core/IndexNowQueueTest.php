<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\PageService;
use Itk\Cms\Entity\User;
use Itk\Cms\Event\RecordDeleting;
use Itk\Cms\Event\RecordPublished;
use Itk\Cms\Event\RecordPublishing;
use Itk\Cms\Event\RecordUnpublishing;
use Itk\Cms\IndexNow\Model\PingIndexNow;
use Itk\Cms\Resource\Record;
use Itk\Cms\Security\Access;
use Itk\Cms\Tests\Core\Fixture\IndexNowSettings;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** Публикация, смена адреса, снятие, удаление и возврат страницы ставят в очередь IndexNow её живой адрес; черновики, noindex, другие языки, записи коллекций и выключенный переключатель молчат. */
final class IndexNowQueueTest extends KernelTestCase
{
    use IndexNowSettings;

    private PageService $pages;
    /** @var list<string> */
    private array $ids = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $c->get('security.token_storage')->setToken(new UsernamePasswordToken(new User('test-inq@example.com', 'Очередь', 'admin'), 'itk_admin', []));
        $this->pages = $c->get(PageService::class);
        $this->setting('domain', 'queue.example.org');
        $this->setting('indexNow', true);
        $this->transport()->reset();
    }

    protected function tearDown(): void
    {
        foreach (\array_reverse($this->ids) as $id) {
            try {
                $this->pages->remove($id);
            } catch (\Throwable) {
            }
        }
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceId IN (:ids)')->setParameter('ids', [] === $this->ids ? ['-'] : $this->ids)->execute();
        $this->restoreSettings();
        $this->transport()->reset();
        parent::tearDown();
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    /** @return list<string> the paths queued since the last call */
    private function queued(): array
    {
        $paths = [];
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof PingIndexNow) {
                $paths[] = $message->path;
            }
        }
        $this->transport()->reset();

        return $paths;
    }

    /** @param array<string, mixed> $extra */
    private function draft(string $url, array $extra = [], string $locale = 'ru'): string
    {
        $page = $this->pages->create(['template' => 'default', 'title' => 'IndexNow ' . $url, 'url' => $url, 'locale' => $locale] + $extra);
        $this->ids[] = $page['id'];
        $this->queued();

        return $page['id'];
    }

    private function live(string $url): string
    {
        $id = $this->draft($url);
        $this->pages->publish($id);
        $this->queued();

        return $id;
    }

    #[Test]
    public function aFirstPublishQueuesTheNewAddressOnly(): void
    {
        $id = $this->draft('/test-in-new');

        $this->pages->publish($id);

        self::assertSame(['/test-in-new'], $this->queued());
    }

    #[Test]
    public function aDraftAndAnEditQueueNothing(): void
    {
        $id = $this->draft('/test-in-draft');
        $this->pages->update($id, null, ['title' => 'Другой заголовок']);

        self::assertSame([], $this->queued());
    }

    #[Test]
    public function publishingChangesOfALivePageQueuesItsAddress(): void
    {
        $id = $this->live('/test-in-again');
        $this->pages->update($id, null, ['title' => 'Новый заголовок']);

        $this->pages->publish($id);

        self::assertSame(['/test-in-again'], \array_values(\array_unique($this->queued())));
    }

    #[Test]
    public function publishingANewAddressQueuesTheOldOneAndTheNewOne(): void
    {
        $id = $this->live('/test-in-old');
        $this->pages->update($id, null, ['url' => '/test-in-moved']);

        $this->pages->publish($id);

        self::assertEqualsCanonicalizing(['/test-in-old', '/test-in-moved'], $this->queued());
    }

    #[Test]
    public function unpublishingAndDeletingQueueTheAddressThatLeaves(): void
    {
        $id = $this->live('/test-in-leaves');

        $this->pages->unpublish($id);
        self::assertSame(['/test-in-leaves'], $this->queued(), 'unpublish');

        $this->pages->republish($id);
        self::assertSame(['/test-in-leaves'], $this->queued(), 'republish is a publish of the old copy');

        $this->pages->remove($id);
        self::assertSame(['/test-in-leaves'], $this->queued(), 'delete');
    }

    #[Test]
    public function deletingADraftQueuesNothing(): void
    {
        $id = $this->draft('/test-in-draft-delete');

        $this->pages->remove($id);

        self::assertSame([], $this->queued());
    }

    #[Test]
    public function aNoindexPageIsNeverAnnounced(): void
    {
        $id = $this->draft('/test-in-noindex', ['seo' => ['robots' => 'noindex,follow']]);

        $this->pages->publish($id);
        $this->pages->unpublish($id);

        self::assertSame([], $this->queued());
    }

    #[Test]
    public function aVersionInAnotherLanguageIsNeverAnnounced(): void
    {
        $id = $this->draft('/test-in-lang-en', [], 'en');

        $this->pages->publish($id, 'en');
        $this->pages->unpublish($id, 'en');

        self::assertSame([], $this->queued());
    }

    #[Test]
    public function aCollectionItemIsNeverAnnounced(): void
    {
        $id = $this->live('/test-in-item-twin');
        $viewer = self::getContainer()->get(Access::class)->viewer();
        $item = new Record($id, 'item', 'test_trips', 'ru', [], 'published');

        $events = self::getContainer()->get('event_dispatcher');
        foreach ([RecordPublishing::class, RecordPublished::class, RecordUnpublishing::class, RecordDeleting::class] as $class) {
            $events->dispatch(new $class('test_trips', 'item', $item, [], $viewer));
        }

        self::assertSame([], $this->queued());
    }

    #[Test]
    public function withTheToggleOffNothingIsQueued(): void
    {
        $id = $this->draft('/test-in-off');
        $this->setting('indexNow', false);

        $this->pages->publish($id);
        $this->pages->unpublish($id);

        self::assertSame([], $this->queued());
    }

    #[Test]
    public function anAddressThatIsNotPublicQueuesNothing(): void
    {
        $id = $this->draft('/test-in-local');
        $this->setting('domain', 'localhost:8000');

        $this->pages->publish($id);

        self::assertSame([], $this->queued());
    }
}
