<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\PageService;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/** Адреса, которые ядро отвечает само (/mcp, /healthz, /robots.txt, /llms.txt), нельзя отдать странице: её там не увидят. */
final class ReservedUrlTest extends KernelTestCase
{
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true);
    }

    #[Test]
    public function theAddressesOfTheCoreAreNotForPages(): void
    {
        self::bootKernel();
        $pages = self::getContainer()->get(PageService::class);

        foreach (['/healthz' => 'проверка', '/HEALTHZ/' => 'проверка', '/mcp' => 'ИИ-ассистентов', '/robots.txt' => 'поисковых роботов', '/ROBOTS.txt' => 'поисковых роботов', '/llms.txt' => 'ИИ-сервисов'] as $url => $what) {
            try {
                $pages->create(['template' => 'default', 'title' => 'TestReserved', 'url' => $url]);
                self::fail($url . ' must be refused');
            } catch (ContentException $e) {
                self::assertStringContainsString('служебной частью', $e->getMessage(), $url);
                self::assertStringContainsString($what, $e->getMessage(), $url);
            }
        }
    }
}
