<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\PageService;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\Support\Format;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Tests\Core\Fixture\McpSupport;
use Itk\Cms\Tests\Core\Fixture\PhotosSection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** MCP и фотографии: отправленное сохраняется в своей форме, а прочитанное из страницы принимается обратно, даже если файла уже нет в медиатеке. */
final class McpPhotosTest extends KernelTestCase
{
    use McpSupport;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->mcpBoot();
        self::getContainer()->get(ResourceRegistry::class)->add(new PhotosSection());
    }

    protected function tearDown(): void
    {
        $this->mcpClean();
        parent::tearDown();
    }

    /** A page with the section, written the way the admin and old tools wrote it: files that the library does not have (any more). @return array{0: \Itk\Cms\Mcp\McpContext, 1: string, 2: array<string, mixed>} */
    private function pageWithGone(): array
    {
        $user = $this->mcpUser('admin');
        $ctx = $this->mcpContext($this->mcpForcedKey($user, [McpScopes::READ, McpScopes::WRITE], 'TestMcp key')['token']);
        $page = $this->mcpPage($ctx, 'TestMcp Photos', '/test-mcp-photos');
        $data = [
            'cover' => ['src' => '/uploads/media/gone/cover.jpg', 'alt' => 'Обложка'],
            'gallery' => ['/uploads/media/gone/a.jpg', ['src' => '/uploads/media/gone/b.jpg', 'alt' => 'Б']],
            'rooms' => [['_id' => 'testroom1', 'title' => 'Люкс', 'photos' => ['/uploads/media/gone/c.jpg', ['src' => '/uploads/media/gone/d.jpg', 'alt' => 'Д']]]],
        ];
        self::getContainer()->get('security.token_storage')->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(new \Itk\Cms\Entity\User('test-mcp-photos@example.com', 'h', 'admin'), 'itk_admin', []));
        $pages = self::getContainer()->get(PageService::class);
        $pages->saveDraft($page['id'], null, ['sections' => [...\array_values((array) Format::plain($pages->get($page['id'])['sections'])), ['id' => 'testphotos1', 'type' => 'test_photos', 'hidden' => false, 'data' => $data]]]);

        return [$ctx, $page['id'], $data];
    }

    #[Test]
    public function whatWasReadFromAPageIsAcceptedBackWithoutChangesWhenTheFilesAreGone(): void
    {
        [$ctx, $pageId, $data] = $this->pageWithGone();
        $read = fn (): array => $this->mcpCall($ctx, 'page_get', ['id' => $pageId, 'section_id' => 'testphotos1'])['data']['sections'][0]['data'];
        self::assertSame($data, $read(), 'the shape of every item is as it was written');

        $r = $this->mcpCall($ctx, 'page_section_update', ['page_id' => $pageId, 'section_id' => 'testphotos1', 'data' => $read()]);

        self::assertTrue($r['ok'], $r['text']);
        self::assertSame($data, $read(), 'strings stay strings, objects keep their alt');
    }

    #[Test]
    public function aGoneFileIsKnownInEachFieldOfTheSectionButAMadeUpOneIsStillRefused(): void
    {
        [$ctx, $pageId] = $this->pageWithGone();
        $update = fn (array $data): array => $this->mcpCall($ctx, 'page_section_update', ['page_id' => $pageId, 'section_id' => 'testphotos1', 'data' => $data]);

        self::assertTrue($update(['gallery' => ['/uploads/media/gone/c.jpg', ['src' => '/uploads/media/gone/a.jpg', 'alt' => 'новое']]])['ok'], 'an address of the page is kept even in another field and shape');

        $bad = $update(['gallery' => ['/uploads/media/made-up.jpg', ['src' => '/uploads/media/made-up-2.jpg']], 'rooms' => [['_id' => 'testroom1', 'photos' => ['https://evil.example/x.png']]]]);
        self::assertFalse($bad['ok']);
        self::assertStringContainsString('data.gallery[0]: "/uploads/media/made-up.jpg" is not in the media library', $bad['text']);
        self::assertStringContainsString('data.gallery[1].src: "/uploads/media/made-up-2.jpg" is not in the media library', $bad['text']);
        self::assertStringContainsString('data.rooms[0].photos[0]: "https://evil.example/x.png" is not in the media library', $bad['text']);
    }

    #[Test]
    public function theShapeOfTheItemsIsKeptWhenAListIsReplaced(): void
    {
        [$ctx, $pageId] = $this->pageWithGone();

        $r = $this->mcpCall($ctx, 'page_section_update', ['page_id' => $pageId, 'section_id' => 'testphotos1', 'data' => ['gallery' => ['/uploads/media/gone/b.jpg', ['src' => '/uploads/media/gone/a.jpg', 'alt' => 'А']]]]);

        self::assertTrue($r['ok'], $r['text']);
        $gallery = $this->mcpCall($ctx, 'page_get', ['id' => $pageId, 'section_id' => 'testphotos1'])['data']['sections'][0]['data']['gallery'];
        self::assertSame(['/uploads/media/gone/b.jpg', ['src' => '/uploads/media/gone/a.jpg', 'alt' => 'А']], $gallery);
    }
}
