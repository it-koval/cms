<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\Listing;
use Itk\Cms\Tests\Core\Fixture\ResourceKernelTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Автоматический список страницы отдаёт у каждой страницы её родителя, чтобы сайт мог сгруппировать список без своего обхода дерева. */
final class ListingTest extends ResourceKernelTestCase
{
    #[Test]
    public function everyListedPageCarriesItsParent(): void
    {
        $blog = $this->page('blog', 'Блог', '/test-listing-blog');
        $this->pages->publish($blog['id']);
        $category = $this->page('blog_category', 'Рубрика', '/test-listing-blog/rubrika', $blog['id']);
        $this->pages->publish($category['id']);
        $inCategory = $this->page('blog_article', 'В рубрике', '/test-listing-blog/rubrika/a', $category['id'], ['title' => 'В рубрике', 'body' => 'Текст']);
        $this->pages->publish($inCategory['id']);
        $inBlog = $this->page('blog_article', 'Прямо в блоге', '/test-listing-blog/b', $blog['id'], ['title' => 'Прямо в блоге', 'body' => 'Текст']);
        $this->pages->publish($inBlog['id']);

        $parents = [];
        foreach (self::getContainer()->get(Listing::class)->forPage($blog['id']) as $item) {
            $parents[$item['id']] = $item['parentId'] ?? 'missing';
        }

        self::assertSame([$inCategory['id'] => $category['id'], $inBlog['id'] => $blog['id']], $parents);
    }
}
