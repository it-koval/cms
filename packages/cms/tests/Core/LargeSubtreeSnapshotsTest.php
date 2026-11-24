<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Repository\PageTranslationRepository;
use Itk\Cms\Tests\App\QueryCounter;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LargeSubtreeSnapshotsTest extends KernelTestCase
{
    #[Test]
    public function moreThanThePostgresParameterLimitStillFindsOnlyLiveRequestedSnapshots(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $page = new Page();
        $t = new PageTranslation($page, 'ru');
        $t->status = PageTranslation::PUBLISHED;
        $t->published = ['title' => 'Large subtree test', 'url' => '/test-subtree-' . \bin2hex(\random_bytes(6))];
        $t->publishedUrl = $t->published['url'];
        $page->translations->set('ru', $t);
        $em->persist($page);
        $em->flush();
        try {
            $ids = [];
            for ($i = 0; $i < 65534; ++$i) {
                $ids[] = \sprintf('00000000-0000-4000-8000-%012d', $i);
            }
            $ids[32767] = $page->id;
            $rows = [];
            $queries = QueryCounter::during(static function () use ($ids, &$rows): void {
                $rows = self::getContainer()->get(PageTranslationRepository::class)->findLiveSnapshotsForPages('ru', $ids);
            });
            self::assertSame([$page->id], \array_keys($rows));
            self::assertSame($t->published['title'], $rows[$page->id]['title']);
            self::assertSame($t->published['url'], $rows[$page->id]['url']);
            self::assertCount(66, $queries);
            foreach ($queries as $sql) {
                self::assertLessThanOrEqual(1002, \substr_count($sql, '?'));
            }
            self::assertSame([], self::getContainer()->get(PageTranslationRepository::class)->findLiveSnapshotsForPages('en', $ids));
        } finally {
            $em->clear();
            $em->getConnection()->executeStatement('DELETE FROM itk_page WHERE id = ?', [$page->id]);
        }
    }
}
