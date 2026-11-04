<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\ChangeDetector;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** «Есть неопубликованные изменения»: сравнение черновика со снимком опубликованной версии. */
final class ChangeDetectorTest extends KernelTestCase
{
    private ChangeDetector $detector;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->detector = self::getContainer()->get(ChangeDetector::class);
    }

    /** @return array<string, mixed> */
    private function page(string $headline = 'site'): array
    {
        return [
            'title' => 'Главная',
            'url' => '/',
            'seo' => ['title' => 'Главная', 'description' => ''],
            'fields' => [],
            'sections' => [
                ['id' => 'sa', 'type' => 'hero', 'hidden' => false, 'data' => ['headline' => $headline . '<br>customs']],
                ['id' => 'sb', 'type' => 'video', 'hidden' => false, 'data' => []],
            ],
        ];
    }

    #[Test]
    public function aNeverPublishedPageHasNothingToCompareWith(): void
    {
        self::assertSame([], $this->detector->pageChanges($this->page(), 'home', null));
    }

    #[Test]
    public function identicalVersionsHaveNoChanges(): void
    {
        self::assertSame([], $this->detector->pageChanges($this->page(), 'home', $this->page()));
    }

    #[Test]
    public function anEditedFieldIsReportedForItsSection(): void
    {
        $changes = $this->detector->pageChanges($this->page('site TEST'), 'home', $this->page());

        self::assertCount(1, $changes);
        self::assertSame('edit', $changes[0]['kind']);
        self::assertSame('sa', $changes[0]['sec']);
        self::assertSame('headline', $changes[0]['key']);
        self::assertSame(['sa'], $this->detector->changedSectionIds($this->page('site TEST'), 'home', $this->page()));
    }

    #[Test]
    public function titleAndAddressAreMetaChanges(): void
    {
        $draft = $this->page();
        $draft['title'] = 'Другое название';
        $draft['url'] = '/novyj';

        $kinds = \array_column($this->detector->pageChanges($draft, 'home', $this->page()), 'kind');

        self::assertSame(['meta', 'meta'], $kinds);
    }

    #[Test]
    public function addingHidingRemovingAndMovingSectionsAreSeparateKinds(): void
    {
        $published = $this->page();

        $added = $this->page();
        $added['sections'][] = ['id' => 'sc', 'type' => 'video', 'hidden' => false, 'data' => []];
        self::assertSame(['add'], \array_column($this->detector->pageChanges($added, 'home', $published), 'kind'));

        $hidden = $this->page();
        $hidden['sections'][1]['hidden'] = true;
        self::assertSame(['vis'], \array_column($this->detector->pageChanges($hidden, 'home', $published), 'kind'));

        $removed = $this->page();
        \array_pop($removed['sections']);
        self::assertSame(['del'], \array_column($this->detector->pageChanges($removed, 'home', $published), 'kind'));

        $moved = $this->page();
        $moved['sections'] = \array_reverse($moved['sections']);
        self::assertSame(['move'], \array_column($this->detector->pageChanges($moved, 'home', $published), 'kind'));
    }
}
