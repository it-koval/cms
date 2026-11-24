<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Itk\Cms\Content\CollectionService;
use Itk\Cms\Field\Repeater;
use Itk\Cms\Field\Text;
use Itk\Cms\Field\Textarea;
use Itk\Cms\KnowledgeBase\Extractor;
use Itk\Cms\KnowledgeBase\Indexer;
use Itk\Cms\KnowledgeBase\Model\Freshness;
use Itk\Cms\KnowledgeBase\Model\SourceKind;
use Itk\Cms\KnowledgeBase\Model\SourceRef;
use Itk\Cms\KnowledgeBase\SourceReader;
use Itk\Cms\Resource\CollectionResource;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Tests\App\FakeEmbedder;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class KnowledgeTitleVisibilityTest extends KernelTestCase
{
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, itk: ['knowledge_base' => ['enabled' => true]]);
    }

    #[Test]
    public function aClosedTitleIsNeutralizedAndSyncReplacesExistingHeadingsAndVectors(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $unique = \bin2hex(\random_bytes(6));
        $resource = new class('f1_titles_' . $unique) extends CollectionResource {
            public readonly Text $heading;

            public function __construct(private readonly string $resourceKey)
            {
                $this->heading = Text::make('Title', 'title');
            }

            public function key(): string
            {
                return $this->resourceKey;
            }

            public function title(): string
            {
                return 'Knowledge title fixture';
            }

            public function titleField(): ?string
            {
                return 'title';
            }

            public function fields(): iterable
            {
                yield $this->heading;
                yield Text::make('Public body', 'body');
                yield Repeater::make('Rows', 'rows')->fields(
                    Text::make('Raw value', 'rawValue')->raw(),
                    Text::make('Public heading', 'heading'),
                    Textarea::make('Public row text', 'body'),
                );
            }
        };
        $c->get(ResourceRegistry::class)->add($resource);
        $items = $c->get(CollectionService::class);
        $db = $c->get(Connection::class);
        $embedder = $c->get(FakeEmbedder::class);
        $private = 'Private title fixture ' . $unique;
        $raw = 'Raw row fixture ' . $unique;
        $publicHeading = 'Public row heading ' . $unique;
        $item = $items->create($resource->key(), [
            'title' => $private,
            'body' => 'Public body fixture ' . $unique,
            'rows' => [['rawValue' => $raw, 'heading' => $publicHeading, 'body' => 'Public row body ' . $unique]],
        ]);
        $id = $item['id'];
        try {
            $items->publish($resource->key(), $id);
            $ref = new SourceRef(SourceKind::Item, $id, 'ru');
            $indexer = $c->get(Indexer::class);
            $indexer->sync([$ref]);
            self::assertSame($private, $db->fetchOne('SELECT title FROM itk_kb_document WHERE source_id = ?', [$id]));

            $resource->heading->canSee('set:users');
            $embedder->forget();
            $live = $c->get(SourceReader::class)->read($ref);
            self::assertNotNull($live);
            self::assertSame($resource->title(), $live->source->title);
            self::assertContains($publicHeading, \array_column($c->get(Extractor::class)->extract($live), 'heading'));
            self::assertSame(Freshness::Stale, $indexer->plan([$ref])[0]->state, 'the upgrade sync must detect the old title without a new publish');
            $indexer->sync([$ref]);
            self::assertSame(Freshness::Current, $indexer->plan([$ref])[0]->state);
            self::assertSame($resource->title(), $db->fetchOne('SELECT title FROM itk_kb_document WHERE source_id = ?', [$id]));
            self::assertNotEmpty($embedder->embeddedDocuments());
            self::assertStringNotContainsString($private, \implode(' ', $embedder->embeddedDocuments()));
            self::assertStringNotContainsString($raw, \implode(' ', $embedder->embeddedDocuments()));
            $chunks = $db->fetchAllAssociative('SELECT c.heading, c.text FROM itk_kb_chunk c JOIN itk_kb_document d ON d.id = c.document_id WHERE d.source_id = ?', [$id]);
            $indexed = \json_encode($chunks, \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($private, $indexed);
            self::assertStringNotContainsString($raw, $indexed);
            self::assertStringContainsString($publicHeading, $indexed);
        } finally {
            $items->remove($resource->key(), $id);
            $db->executeStatement('DELETE FROM itk_revision WHERE resource_id = ?', [$id]);
            $embedder->forget();
            $c->get('messenger.transport.knowledge_base')->reset();
        }
    }
}
