<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\CollectionQuery;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Field\Text;
use Itk\Cms\Resource\CollectionResource;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\Tests\Core\Fixture\ResourceKernelTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Uid\Uuid;

final class ContentReadRegressionTest extends ResourceKernelTestCase
{
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, itk: ['locales' => ['ru' => ['name' => 'Русский'], 'kk' => ['name' => 'Қазақша'], 'en' => ['name' => 'English']]]);
    }

    private string $key;
    private string $id;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->key = 'test_single_' . \bin2hex(\random_bytes(5));
        $this->id = Uuid::v7()->toRfc4122();
        self::getContainer()->get(ResourceRegistry::class)->add(new class($this->key, $this->id) extends CollectionResource {
            public function __construct(private string $name, private string $id)
            {
            }

            public function key(): string
            {
                return $this->name;
            }

            public function title(): string
            {
                return 'Singleton';
            }

            public function fields(): iterable
            {
                yield Text::make('Title', 'title')->initial('Initial')->filterable()->sortable()->searchable();
            }
        });
        $this->prepare();
        self::getContainer()->get(Schema::class)->collection($this->key)->singleton($this->id);
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM Itk\Cms\Entity\CollectionItem i WHERE i.id = :id')->setParameter('id', $this->id)->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceId = :id')->setParameter('id', $this->id)->execute();
        parent::tearDown();
    }

    #[Test]
    public function readingASingletonDoesNotCreateContentOrHistoryAndSavingDoes(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $c = self::getContainer()->get(Schema::class)->collection($this->key);
        for ($i = 0; $i < 2; ++$i) {
            $result = $this->collections->get($this->key, $this->id);
            self::assertSame('Initial', $result['item']['title']);
            self::assertSame($this->id, $this->collections->list($this->key)[0]['id']);
            self::assertSame(1, self::getContainer()->get(CollectionQuery::class)->run($c, [])['total']);
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM itk_collection_item WHERE id = ?', [$this->id]));
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM itk_revision WHERE resource_id = ?', [$this->id]));
        }
        $saved = $this->collections->update($this->key, $this->id, ['title' => 'Saved'], baseVersion: $result['item']['version']);
        self::assertSame('Saved', $saved['title']);
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM itk_collection_item WHERE id = ?', [$this->id]));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM itk_revision WHERE resource_id = ?', [$this->id]));
    }

    #[Test]
    public function explicitlyAddingASingletonTranslationMaterializesTheMainVersion(): void
    {
        self::assertNull($this->collections->get($this->key, $this->id, 'kk')['item']);
        $translated = $this->collections->addTranslation($this->key, $this->id, 'kk', 'ru');
        self::assertSame('Initial', $translated['title']);
        self::assertSame('Initial', $this->collections->get($this->key, $this->id, 'ru')['item']['title']);
        self::assertSame('Initial', $this->collections->get($this->key, $this->id, 'kk')['item']['title']);
    }

    #[Test]
    public function firstSaveInASecondaryLanguageKeepsTheInitialMainVersion(): void
    {
        $saved = $this->collections->update($this->key, $this->id, ['title' => 'Saved kk'], 'kk');
        self::assertSame('Saved kk', $saved['title']);
        self::assertSame('Initial', $this->collections->get($this->key, $this->id, 'ru')['item']['title']);
        self::assertSame('Saved kk', $this->collections->get($this->key, $this->id, 'kk')['item']['title']);
    }

    #[Test]
    public function failedExplicitTranslationDoesNotLeaveAMaterializedSingleton(): void
    {
        try {
            $this->collections->addTranslation($this->key, $this->id, 'kk', 'en');
            self::fail('Missing source must reject the operation');
        } catch (ContentException $e) {
            self::assertSame(422, $e->status());
        }
        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM itk_collection_item WHERE id = ?', [$this->id]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM itk_revision WHERE resource_id = ?', [$this->id]));
    }

    #[Test]
    public function firstPublishMaterializesAndPublishesTheMainVersion(): void
    {
        $initial = $this->collections->get($this->key, $this->id)['item'];
        $published = $this->collections->publish($this->key, $this->id, baseVersion: $initial['version']);
        self::assertSame('published', $published['status']);
        self::assertSame('Initial', $published['title']);
        self::assertSame('Initial', $this->collections->get($this->key, $this->id)['published']['title']);
    }

    #[Test]
    public function aSingletonCannotReadAnExistingItemFromAnotherCollection(): void
    {
        $foreign = $this->trip('Foreign');
        self::getContainer()->get(Schema::class)->collection($this->key)->singleton($foreign['id']);
        try {
            $this->collections->get($this->key, $foreign['id']);
            self::fail('Foreign item returned as this singleton');
        } catch (ContentException $e) {
            self::assertSame(404, $e->status());
        }
    }

    #[Test]
    public function initialSingletonUsesTheNormalQueryFiltersAndValidation(): void
    {
        $query = self::getContainer()->get(CollectionQuery::class);
        $c = self::getContainer()->get(Schema::class)->collection($this->key);
        self::assertSame(1, $query->run($c, ['filter' => ['title' => 'Init']])['total']);
        self::assertSame(0, $query->run($c, ['filter' => ['title' => 'Missing']])['total']);
        foreach ([['status' => 'unknown'], ['filter' => ['unknown' => 'value']], ['sort' => 'unknown']] as $params) {
            try {
                $query->run($c, $params);
                self::fail('Invalid query accepted');
            } catch (ContentException $e) {
                self::assertSame(422, $e->status());
            }
        }
    }

    #[Test]
    public function routesAcceptCamelCaseKeysSupportedByTheSchema(): void
    {
        $router = self::getContainer()->get('router');
        foreach ([['GET', '/admin/api/collections/faqItems', 'col'], ['POST', '/admin/api/collections/faqItems', 'col'], ['POST', '/request/faqItems', 'form']] as [$method, $path, $key]) {
            $router->getContext()->setMethod($method);
            self::assertSame('faqItems', $router->match($path)[$key]);
        }
    }
}
