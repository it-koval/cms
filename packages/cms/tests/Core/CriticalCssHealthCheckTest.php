<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\PageService;
use Itk\Cms\CriticalCss\CriticalCssService;
use Itk\Cms\CriticalCss\Stylesheets;
use Itk\Cms\Entity\CriticalCss;
use Itk\Cms\Health\CriticalCssHealthCheck;
use Itk\Cms\Health\HealthResult;
use Itk\Cms\Repository\PageRepository;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/** «Критический CSS» в «Состоянии сайта»: выключен, нет файла стилей, нет Node или crittr, есть неудачи, иначе «N из M». Chrome не запускается. */
final class CriticalCssHealthCheckTest extends KernelTestCase
{
    private const CSS = '/css/test-health.css';

    private ?string $pageId = null;

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', [], [], $options['itk'] ?? ['critical_css' => ['enabled' => true, 'stylesheets' => [self::CSS]]]);
    }

    private static function appDir(): string
    {
        return \dirname(__DIR__) . '/App';
    }

    protected function setUp(): void
    {
        @\mkdir(self::appDir() . '/public/css', 0o777, true);
        \file_put_contents(self::appDir() . '/public' . self::CSS, 'body{margin:0}');
        @\mkdir(self::appDir() . '/node_modules/crittr', 0o777, true);
        \file_put_contents(self::appDir() . '/node_modules/crittr/package.json', '{"name":"crittr"}');
        unset($_ENV['ITK_FAKE_NODE_VERSION']);
    }

    protected function tearDown(): void
    {
        @\unlink(self::appDir() . '/node_modules/crittr/package.json');
        unset($_ENV['ITK_FAKE_NODE_VERSION']);
        if (null !== $this->pageId) {
            self::getContainer()->get(PageService::class)->remove($this->pageId);
        }
        parent::tearDown();
    }

    private function check(string $node = __DIR__ . '/../App/bin/fake-node.sh'): HealthResult
    {
        $c = self::getContainer();

        return (new CriticalCssHealthCheck($c->get(Stylesheets::class), $c->get(Connection::class), $c->get(CriticalCssService::class), $node, self::appDir()))->check();
    }

    #[Test]
    public function offIsFineAndNeedsNoNode(): void
    {
        self::bootKernel(['itk' => ['critical_css' => ['enabled' => false]]]);

        $result = $this->check('/no/such/node');

        self::assertSame(HealthResult::OFF, $result->status);
        self::assertStringContainsString('Выключен', $result->message);
    }

    #[Test]
    public function whatStopsEveryPageIsAFailure(): void
    {
        self::bootKernel();

        \unlink(self::appDir() . '/public' . self::CSS);
        $result = $this->check();
        self::assertSame(HealthResult::FAIL, $result->status);
        self::assertStringContainsString(self::CSS, $result->message);
        \file_put_contents(self::appDir() . '/public' . self::CSS, 'body{margin:0}');

        $result = $this->check('/no/such/node');
        self::assertSame(HealthResult::FAIL, $result->status);
        self::assertStringContainsString('/no/such/node', $result->message);

        $_ENV['ITK_FAKE_NODE_VERSION'] = 'v20.5.0';
        $result = $this->check();
        self::assertSame(HealthResult::FAIL, $result->status);
        self::assertStringContainsString('v20.5.0', $result->message);
        unset($_ENV['ITK_FAKE_NODE_VERSION']);

        \unlink(self::appDir() . '/node_modules/crittr/package.json');
        $result = $this->check();
        self::assertSame(HealthResult::FAIL, $result->status);
        self::assertStringContainsString('npm install crittr', $result->message);
    }

    #[Test]
    public function failedPagesAreAWarningAndTheRestIsACount(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $pages = $c->get(PageService::class);
        $page = $pages->create(['template' => 'default', 'title' => 'Здоровье', 'url' => '/test-health-cc']);
        $this->pageId = $page['id'];
        $pages->publish($page['id']);

        $ok = $this->check();
        self::assertSame(HealthResult::OK, $ok->status);
        self::assertMatchesRegularExpression('~0 из \d+~', $ok->message);

        $em = $c->get(EntityManagerInterface::class);
        $t = $c->get(PageRepository::class)->find($page['id'])?->translation('ru');
        self::assertNotNull($t);
        $row = CriticalCss::for($t);
        $row->markFailed($c->get(CriticalCssService::class)->wanted($t)->value, '', 'Chrome did not start', new \DateTimeImmutable());
        $em->persist($row);
        $em->flush();

        $warn = $this->check();
        self::assertSame(HealthResult::WARN, $warn->status);
        self::assertStringContainsString('Chrome did not start', $warn->message);
        self::assertStringContainsString('1', $warn->message);

        $pages->unpublish($page['id']);
        self::assertSame(HealthResult::OK, $this->check()->status);
    }

    #[Test]
    public function readyRowsAreCountedByTheStampAndStaysOk(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $pages = $c->get(PageService::class);
        $page = $pages->create(['template' => 'default', 'title' => 'Штамп', 'url' => '/test-health-stamp']);
        $this->pageId = $page['id'];
        $pages->publish($page['id']);
        $em = $c->get(EntityManagerInterface::class);
        $service = $c->get(CriticalCssService::class);
        $t = $c->get(PageRepository::class)->find($page['id'])?->translation('ru');
        self::assertNotNull($t);
        $row = CriticalCss::for($t);
        $row->markReady($service->wanted($t)->value, 'inputs', [['media' => null, 'css' => 'a{x:1}']], new \DateTimeImmutable(), $service->stamp());
        $em->persist($row);
        $em->flush();

        $current = $this->check();
        self::assertSame(HealthResult::OK, $current->status);
        self::assertMatchesRegularExpression('~Актуален у 1 из \d+~', $current->message);
        self::assertStringNotContainsString('прежний', $current->message);

        \file_put_contents(self::appDir() . '/public' . self::CSS, 'body{margin:0;padding:0}');
        try {
            $previous = $this->check();
            self::assertSame(HealthResult::OK, $previous->status);
            self::assertMatchesRegularExpression('~Актуален у 0 из \d+~', $previous->message);
            self::assertStringContainsString('у 1 прежний', $previous->message);

            $c->get(Connection::class)->executeStatement('UPDATE itk_critical_css SET stamp = NULL WHERE translation_id = ?', [$t->id]);
            self::assertStringContainsString('у 1 прежний', $this->check()->message, 'a row without a stamp is not current');

            $pages->unpublish($page['id']);
            self::assertStringNotContainsString('прежний', $this->check()->message);
        } finally {
            \file_put_contents(self::appDir() . '/public' . self::CSS, 'body{margin:0}');
        }
    }
}
