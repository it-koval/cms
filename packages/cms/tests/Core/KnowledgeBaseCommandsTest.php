<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Itk\Cms\Content\PageService;
use Itk\Cms\Health\HealthResult;
use Itk\Cms\Health\KnowledgeBaseHealthCheck;
use Itk\Cms\KnowledgeBase\Embedding\EmbeddingFailed;
use Itk\Cms\KnowledgeBase\KnowledgeBaseOptions;
use Itk\Cms\Tests\App\FakeEmbedder;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Команды itk:knowledge-base:sync и itk:knowledge-base:search и строка «База знаний» в «Состоянии сайта»: коды выхода, что ставится в очередь,
 * что печатается, и что строка говорит о выключенной базе, смене модели и числе проиндексированных версий.
 */
final class KnowledgeBaseCommandsTest extends KernelTestCase
{
    /** @var list<string> */
    private array $pages = [];

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', [], [], $options['itk'] ?? ['knowledge_base' => ['enabled' => true]]);
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
        $c->get(FakeEmbedder::class)->forget();
        $transport = $c->get('messenger.transport.knowledge_base');
        \assert($transport instanceof InMemoryTransport);
        $transport->reset();
        parent::tearDown();
    }

    private function publish(string $url, string $title): string
    {
        $pages = self::getContainer()->get(PageService::class);
        $page = $pages->create(['template' => 'default', 'title' => $title, 'url' => $url, 'sections' => [['id' => 's1', 'type' => 'text', 'hidden' => false, 'data' => ['text' => '<p>Текст про ' . $title . '.</p>']]]]);
        $this->pages[] = $page['id'];
        $pages->publish($page['id']);

        return $page['id'];
    }

    /** @param array<string, mixed> $input */
    private function execute(string $command, array $input = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find($command));
        $tester->execute($input);

        return $tester;
    }

    private function queued(): int
    {
        $transport = self::getContainer()->get('messenger.transport.knowledge_base');
        \assert($transport instanceof InMemoryTransport);

        return \count($transport->getSent());
    }

    private function health(): HealthResult
    {
        $c = self::getContainer();

        return (new KnowledgeBaseHealthCheck($c->get(Connection::class), $c->get(FakeEmbedder::class), $c->get(KnowledgeBaseOptions::class), null, null))->check();
    }

    #[Test]
    public function syncQueuesWhatIsMissingAndDoesItWithNow(): void
    {
        self::bootKernel();
        $id = $this->publish('/kb-cmd-sync', 'Синхронизация');
        self::getContainer()->get('messenger.transport.knowledge_base')->reset(); // the publish queued its own message; the command finds the page Missing itself
        $db = self::getContainer()->get(Connection::class);

        $dry = $this->execute('itk:knowledge-base:sync', ['--dry-run' => true]);
        self::assertSame(Command::SUCCESS, $dry->getStatusCode());
        self::assertMatchesRegularExpression('~Нет: [1-9]\d*~', $dry->getDisplay());
        self::assertSame(0, $this->queued(), 'a dry run sends nothing');
        self::assertSame([], self::getContainer()->get(FakeEmbedder::class)->calls, 'a dry run calls no API');

        $queue = $this->execute('itk:knowledge-base:sync');
        self::assertSame(Command::SUCCESS, $queue->getStatusCode());
        self::assertGreaterThanOrEqual(1, $this->queued());
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM itk_kb_document WHERE source_id = ?', [$id]));

        $now = $this->execute('itk:knowledge-base:sync', ['--now' => true, '--source' => 'page:' . $id]);
        self::assertSame(Command::SUCCESS, $now->getStatusCode(), $now->getDisplay());
        self::assertStringContainsString('Записано: 1', $now->getDisplay());
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM itk_kb_document WHERE source_id = ?', [$id]));

        $again = $this->execute('itk:knowledge-base:sync', ['--now' => true, '--source' => 'page:' . $id . ':ru']);
        self::assertStringContainsString('без изменений: 1', $again->getDisplay());

        self::assertSame(Command::INVALID, $this->execute('itk:knowledge-base:sync', ['--source' => 'menu:x'])->getStatusCode());
    }

    #[Test]
    public function syncWithNowFailsWhenTheEmbedderRefuses(): void
    {
        self::bootKernel();
        $id = $this->publish('/kb-cmd-refused', 'Отказ');
        self::getContainer()->get(FakeEmbedder::class)->failNext = EmbeddingFailed::permanent('bad key');

        $tester = $this->execute('itk:knowledge-base:sync', ['--now' => true, '--source' => 'page:' . $id]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('bad key', $tester->getDisplay());
    }

    #[Test]
    public function searchPrintsTheHitsAndTheHealthLineCountsThem(): void
    {
        self::bootKernel();
        $id = $this->publish('/kb-cmd-search', 'Гарантия');
        $this->execute('itk:knowledge-base:sync', ['--now' => true, '--source' => 'page:' . $id]);

        $tester = $this->execute('itk:knowledge-base:search', ['query' => 'текст про гарантию', '--limit' => '3']);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('/kb-cmd-search', $tester->getDisplay());
        self::assertStringContainsString('Гарантия', $tester->getDisplay());
        self::assertSame(Command::INVALID, $this->execute('itk:knowledge-base:search', ['query' => 'x', '--limit' => '0'])->getStatusCode());

        $ok = $this->health();
        self::assertSame(HealthResult::OK, $ok->status);
        self::assertMatchesRegularExpression('~Проиндексировано [1-9]\d* из \d+ живых версий~', $ok->message);

        self::getContainer()->get(FakeEmbedder::class)->model = 'v2';
        $stale = $this->health();
        self::assertSame(HealthResult::FAIL, $stale->status);
        self::assertStringContainsString('fake:bag-of-words:1536', $stale->message);
        self::assertStringContainsString('itk:knowledge-base:sync --now', $stale->message);
    }

    #[Test]
    public function offTheCommandsRefuseAndTheHealthLineIsFine(): void
    {
        self::bootKernel(['itk' => ['knowledge_base' => ['enabled' => false]]]);

        self::assertSame(Command::INVALID, $this->execute('itk:knowledge-base:sync')->getStatusCode());
        self::assertSame(Command::FAILURE, $this->execute('itk:knowledge-base:search', ['query' => 'x'])->getStatusCode());
        $health = $this->health();
        self::assertSame(HealthResult::OK, $health->status);
        self::assertStringContainsString('Выключена', $health->message);
    }
}
