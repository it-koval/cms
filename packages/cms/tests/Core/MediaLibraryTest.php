<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\MediaFile;
use Itk\Cms\Entity\User;
use Itk\Cms\Media\GenerateImageVariants;
use Itk\Cms\Media\GenerateImageVariantsHandler;
use Itk\Cms\Media\ImageVariants;
use Itk\Cms\Media\MediaService;
use Itk\Cms\Security\UserManager;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Медиатека: папки и перенос файлов, точка фокуса и кадры, варианты по пропорции, WebP и AVIF при загрузке, <picture> в itk_img. */
final class MediaLibraryTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $csrf = '';
    private string $root;

    /** @var list<string> */
    private array $fileIds = [];

    /** @var list<string> */
    private array $folderIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->root = self::getContainer()->getParameter('kernel.project_dir') . '/public';
        $this->signIn(self::getContainer()->get(UserManager::class)->create('test-media@example.com', 'Тест медиа', 'admin', 'password-for-test'));
    }

    protected function tearDown(): void
    {
        $media = self::getContainer()->get(MediaService::class);
        foreach ($this->fileIds as $id) {
            try {
                $media->remove($id);
            } catch (\Throwable) {
            }
        }
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $em->getConnection()->executeStatement('UPDATE itk_media_file SET folder_id = NULL WHERE folder_id IN (SELECT id FROM itk_media_folder WHERE name LIKE \'Test%\')');
        $em->getConnection()->executeStatement('DELETE FROM itk_media_folder WHERE name LIKE \'Test%\' AND parent_id IS NOT NULL');
        $em->getConnection()->executeStatement('DELETE FROM itk_media_folder WHERE name LIKE \'Test%\'');
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-%@example.com')->execute();
        parent::tearDown();
    }

    private function signIn(User $user): void
    {
        $this->client->loginUser($user, 'itk_admin');
        $this->client->request('GET', '/admin');
        \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $m);
        $this->csrf = $m[1] ?? '';
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, array $body = [], int $status = 200): array
    {
        $this->client->request($method, '/admin/api/' . $path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], [] === $body ? null : \json_encode($body));
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return (array) \json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return array<string, mixed> */
    private function folder(string $name, ?string $parentId = null): array
    {
        $f = $this->api('POST', 'media-folders', ['name' => $name, 'parentId' => $parentId], 201);
        $this->folderIds[] = $f['id'];

        return $f;
    }

    /** A 1500 × 900 picture: red on the left half, blue on the right. */
    private function upload(?string $folderId = null, string $name = 'test-split.png'): MediaFile
    {
        $img = \imagecreatetruecolor(1500, 900);
        \imagefilledrectangle($img, 0, 0, 749, 899, (int) \imagecolorallocate($img, 220, 20, 20));
        \imagefilledrectangle($img, 750, 0, 1499, 899, (int) \imagecolorallocate($img, 20, 20, 220));
        $tmp = \sys_get_temp_dir() . '/test-media-' . \bin2hex(\random_bytes(4)) . '.png';
        \imagepng($img, $tmp);
        $m = self::getContainer()->get(MediaService::class)->upload(new UploadedFile($tmp, $name, 'image/png', null, true), $folderId);
        $this->fileIds[] = $m->id;

        return $m;
    }

    /** The colour of the pixel at (fx, fy) of an image served at $url, as [r, g, b]. */
    private function pixel(string $url, float $fx, float $fy): array
    {
        $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $url);
        $file = $this->root . $url;
        self::assertFileExists($file);
        $img = \imagecreatefromwebp($file);
        $rgb = \imagecolorsforindex($img, \imagecolorat($img, (int) (\imagesx($img) * $fx), (int) (\imagesy($img) * $fy)));

        return [$rgb['red'], $rgb['green'], $rgb['blue']];
    }

    #[Test]
    public function foldersFormATreeWithCounts(): void
    {
        $a = $this->folder('Test Журнал');
        $b = $this->folder('Test Лето', $a['id']);
        $m = $this->upload($b['id']);
        $this->upload(null, 'test-loose.png');

        self::assertSame($a['id'], $b['parentId']);
        $tree = $this->api('GET', 'media-folders');
        $byId = [];
        foreach ($tree['folders'] as $f) {
            $byId[$f['id']] = $f;
        }
        self::assertSame(1, $byId[$b['id']]['files']);
        self::assertSame(0, $byId[$a['id']]['files']);
        self::assertGreaterThanOrEqual(1, $tree['unfiled']);
        self::assertGreaterThanOrEqual(2, $tree['total']);
        // the core defaults come first, then the ratios of the layout's fixed image slots (config/packages/itk_cms.yaml)
        self::assertSame(['16:9', '4:3', '1:1', '3:4'], \array_slice($tree['ratios'], 0, 4));
        self::assertContains('14:5', $tree['ratios']);

        $inB = $this->api('GET', 'media?folder=' . $b['id'])['items'];
        self::assertSame([$m->id], \array_column($inB, 'id'));
        self::assertSame($b['id'], $inB[0]['folderId']);
        self::assertNotContains($m->id, \array_column($this->api('GET', 'media?folder=none')['items'], 'id'));
    }

    #[Test]
    public function aFolderNameIsUniqueAmongNeighboursAndCannotBeEmpty(): void
    {
        $this->folder('Test Один');
        $this->api('POST', 'media-folders', ['name' => 'test один'], 409);
        $this->api('POST', 'media-folders', ['name' => '   '], 422);
        $b = $this->folder('Test Два');
        $this->api('PATCH', 'media-folders/' . $b['id'], ['name' => 'TEST ОДИН'], 409);
        $renamed = $this->api('PATCH', 'media-folders/' . $b['id'], ['name' => 'Test Три']);
        self::assertSame('Test Три', $renamed['name']);
        $this->folder('Test Один', $b['id']); // the same name one level down is fine
        $this->api('POST', 'media-folders', ['name' => 'Test Х', 'parentId' => 'no-such'], 404);
    }

    #[Test]
    public function aFolderCannotMoveIntoItselfOrItsChild(): void
    {
        $a = $this->folder('Test Корень');
        $b = $this->folder('Test Ветка', $a['id']);
        $this->api('PATCH', 'media-folders/' . $a['id'], ['parentId' => $b['id']], 422);
        $this->api('PATCH', 'media-folders/' . $a['id'], ['parentId' => $a['id']], 422);
        $moved = $this->api('PATCH', 'media-folders/' . $b['id'], ['parentId' => 'root']);
        self::assertNull($moved['parentId']);
    }

    #[Test]
    public function nestingIsLimited(): void
    {
        $parent = null;
        for ($i = 1; $i <= 6; ++$i) {
            $parent = $this->folder('Test Уровень ' . $i, $parent)['id'];
        }
        $this->api('POST', 'media-folders', ['name' => 'Test Слишком глубоко', 'parentId' => $parent], 422);
    }

    #[Test]
    public function aFolderIsPlacedAmongItsNeighbours(): void
    {
        $a = $this->folder('Test A');
        $b = $this->folder('Test B');
        $c = $this->folder('Test C');
        $this->api('PATCH', 'media-folders/' . $c['id'], ['position' => 0]);
        $ids = \array_column($this->api('GET', 'media-folders')['folders'], 'id');
        $own = \array_values(\array_filter($ids, static fn (string $id): bool => \in_array($id, [$a['id'], $b['id'], $c['id']], true)));
        self::assertSame([$c['id'], $a['id'], $b['id']], $own);
    }

    #[Test]
    public function aFolderWithContentIsRemovedOnlyTogetherWithMovingIt(): void
    {
        $a = $this->folder('Test Старая');
        $child = $this->folder('Test Вложенная', $a['id']);
        $target = $this->folder('Test Новая');
        $m = $this->upload($a['id']);

        $refused = $this->api('DELETE', 'media-folders/' . $a['id'], [], 409);
        self::assertSame(['files' => 1, 'folders' => 1], $refused['details']);
        $this->api('DELETE', 'media-folders/' . $a['id'] . '?moveTo=' . $a['id'], [], 422);
        $this->api('DELETE', 'media-folders/' . $a['id'] . '?moveTo=' . $child['id'], [], 422);

        $this->api('DELETE', 'media-folders/' . $a['id'] . '?moveTo=' . $target['id']);
        $files = $this->api('GET', 'media?folder=' . $target['id'])['items'];
        self::assertSame([$m->id], \array_column($files, 'id'));
        $folders = [];
        foreach ($this->api('GET', 'media-folders')['folders'] as $f) {
            $folders[$f['id']] = $f;
        }
        self::assertArrayNotHasKey($a['id'], $folders);
        self::assertSame($target['id'], $folders[$child['id']]['parentId']);

        $empty = $this->folder('Test Пустая');
        $this->api('DELETE', 'media-folders/' . $empty['id']);
        $this->api('DELETE', 'media-folders/' . $empty['id'], [], 404);
        // to the top level
        $this->api('DELETE', 'media-folders/' . $target['id'] . '?moveTo=root');
        self::assertContains($m->id, \array_column($this->api('GET', 'media?folder=none')['items'], 'id'));
    }

    #[Test]
    public function filesMoveBetweenFoldersAllOrNone(): void
    {
        $a = $this->folder('Test Папка');
        $x = $this->upload();
        $y = $this->upload();

        self::assertSame(['moved' => 2], $this->api('POST', 'media/move', ['ids' => [$x->id, $y->id], 'folderId' => $a['id']]));
        self::assertSame($a['id'], $this->api('GET', 'media/' . $x->id)['folderId']);

        $this->api('POST', 'media/move', ['ids' => [$x->id, 'no-such-file'], 'folderId' => 'root'], 404);
        self::assertSame($a['id'], $this->api('GET', 'media/' . $x->id)['folderId'], 'nothing moved');
        $this->api('POST', 'media/move', ['ids' => [$x->id], 'folderId' => 'no-such-folder'], 404);

        $one = $this->api('PATCH', 'media/' . $x->id, ['folderId' => null]);
        self::assertNull($one['folderId']);
        self::assertSame($a['id'], $this->api('PATCH', 'media/' . $x->id, ['folderId' => $a['id']])['folderId']);
    }

    #[Test]
    public function aPersonWithoutMediaRightsCannotUseFolders(): void
    {
        $this->signIn(self::getContainer()->get(UserManager::class)->create('test-req@example.com', 'Тест заявки', 'requests', 'password-for-test'));
        $this->api('GET', 'media-folders', [], 403);
        $this->api('POST', 'media-folders', ['name' => 'Test Нельзя'], 403);
        $this->api('POST', 'media/move', ['ids' => [], 'folderId' => null], 403);
    }

    #[Test]
    public function aUploadIsQueuedForWebpAndAvif(): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        $before = \count($transport->getSent());
        $m = $this->upload();
        $sent = $transport->getSent();
        self::assertCount($before + 1, $sent);
        $message = $sent[$before]->getMessage();
        self::assertInstanceOf(GenerateImageVariants::class, $message);
        self::assertSame($m->id, $message->mediaId);

        $variants = self::getContainer()->get(ImageVariants::class);
        $made = [];
        foreach ($variants->warmJobs(1500) as [$w, $format]) {
            $file = (string) $variants->variantFileOf($m->path, $w, null, $format);
            self::assertFileDoesNotExist($file);
            $made[] = $file;
        }
        (new GenerateImageVariantsHandler(self::getContainer()->get(\Itk\Cms\Repository\MediaFileRepository::class), $variants))($message);
        foreach ($made as $file) {
            self::assertFileExists($file);
        }
        self::assertContains('avif', \array_map(static fn (string $f): string => \pathinfo($f, \PATHINFO_EXTENSION), $made), 'GD of the test image writes AVIF');
        // the handler survives a file that is gone
        self::getContainer()->get(MediaService::class)->remove($m->id);
        $this->fileIds = [];
        (new GenerateImageVariantsHandler(self::getContainer()->get(\Itk\Cms\Repository\MediaFileRepository::class), $variants))($message);
    }

    #[Test]
    public function aPdfIsNotQueued(): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        $before = \count($transport->getSent());
        $tmp = \sys_get_temp_dir() . '/test-doc-' . \bin2hex(\random_bytes(4)) . '.pdf';
        \file_put_contents($tmp, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
        $m = self::getContainer()->get(MediaService::class)->upload(new UploadedFile($tmp, 'test-doc.pdf', 'application/pdf', null, true));
        $this->fileIds[] = $m->id;
        self::assertCount($before, $transport->getSent());
        $this->api('PATCH', 'media/' . $m->id, ['focal' => ['x' => 0.5, 'y' => 0.5]], 422);
    }

    #[Test]
    public function theFocalPointAndFramesAreValidatedAndSaved(): void
    {
        $m = $this->upload();
        $item = $this->api('GET', 'media/' . $m->id);
        self::assertNull($item['focal']);
        self::assertSame([], $item['crops']);

        foreach ([['x' => 2, 'y' => 0.5], ['x' => 0.5], ['x' => 'a', 'y' => 'b'], 'centre'] as $bad) {
            $this->api('PATCH', 'media/' . $m->id, ['focal' => $bad], 422);
        }
        $saved = $this->api('PATCH', 'media/' . $m->id, ['focal' => ['x' => 0.12345678, 'y' => 0.5]]);
        self::assertSame(['x' => 0.1235, 'y' => 0.5], $saved['focal']);

        $this->api('PATCH', 'media/' . $m->id, ['crops' => ['wide' => ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]]], 422);
        $this->api('PATCH', 'media/' . $m->id, ['crops' => ['16:9' => ['x' => 0.6, 'y' => 0, 'w' => 0.6, 'h' => 0.5]]], 422);
        $this->api('PATCH', 'media/' . $m->id, ['crops' => ['16:9' => ['x' => 0, 'y' => 0, 'w' => 0, 'h' => 0.5]]], 422);
        $this->api('PATCH', 'media/' . $m->id, ['crops' => ['16:9' => ['x' => 0, 'y' => 0]]], 422);

        $saved = $this->api('PATCH', 'media/' . $m->id, ['crops' => ['32:18' => ['x' => 0.5, 'y' => 0, 'w' => 0.5, 'h' => 1], '7:5' => ['x' => 0, 'y' => 0, 'w' => 0.4, 'h' => 0.4]]]);
        self::assertSame(['16:9', '7:5'], \array_keys($saved['crops']), 'a ratio is kept reduced');
        self::assertEquals(['x' => 0.5, 'y' => 0.0, 'w' => 0.5, 'h' => 1.0], $saved['crops']['16:9']);
        self::assertEqualsCanonicalizing($saved['crops'], $this->api('GET', 'media/' . $m->id)['crops'], 'the JSON column hands the keys back in its own order');

        $cleared = $this->api('PATCH', 'media/' . $m->id, ['focal' => null, 'crops' => null]);
        self::assertNull($cleared['focal']);
        self::assertSame([], $cleared['crops']);
    }

    #[Test]
    public function aRatioVariantFollowsTheFocalPoint(): void
    {
        $m = $this->upload();
        $variants = self::getContainer()->get(ImageVariants::class);
        $url = $variants->url($m->path, 640, '16:9');
        self::assertSame(\str_replace('/_v/w640/', '/_v/w640-r16x9/', $variants->url($m->path, 640)), $url);

        $this->client->request('GET', $url);
        $response = $this->client->getResponse();
        self::assertSame('image/webp', $response->headers->get('Content-Type'));
        self::assertStringNotContainsString('immutable', (string) $response->headers->get('Cache-Control'), 'the frame of a file can be redrawn');
        self::assertSame([640, 360], \array_slice(\getimagesize($this->root . $url), 0, 2));
        // 1500 × 900 cut to 16:9 is 1500 × 844: the whole width, red on the left and blue on the right
        self::assertGreaterThan(150, $this->pixel($url, 0.05, 0.5)[0]);
        self::assertGreaterThan(150, $this->pixel($url, 0.95, 0.5)[2]);

        // 1:1 is 900 × 900; at the centre it shows 300..1200 of the width, a point at 30% of the frame is at 570 (red)
        $square = $variants->url($m->path, 640, '1:1');
        [$r, , $b] = $this->pixel($square, 0.25, 0.5);
        self::assertGreaterThan(150, $r);
        self::assertLessThan(80, $b);
        self::assertGreaterThan(150, $this->pixel($square, 0.75, 0.5)[2]);

        // focal point on the left edge: the frame is 0..900, 30% of it is 270 (red); on the right edge it is 600..1500, 30% is 870 (blue)
        $this->api('PATCH', 'media/' . $m->id, ['focal' => ['x' => 0, 'y' => 0.5]]);
        self::assertFileDoesNotExist($this->root . $square, 'the old frames are dropped when the focal point changes');
        [$r, , $b] = $this->pixel($square, 0.3, 0.5);
        self::assertGreaterThan(150, $r);
        self::assertLessThan(80, $b);
        $this->api('PATCH', 'media/' . $m->id, ['focal' => ['x' => 1, 'y' => 0.5]]);
        [$r, , $b] = $this->pixel($square, 0.3, 0.5);
        self::assertLessThan(80, $r);
        self::assertGreaterThan(150, $b);
    }

    #[Test]
    public function aSavedFrameWinsOverTheFocalPoint(): void
    {
        $m = $this->upload();
        $this->api('PATCH', 'media/' . $m->id, ['focal' => ['x' => 0, 'y' => 0.5], 'crops' => ['4:3' => ['x' => 0.5, 'y' => 0, 'w' => 0.5, 'h' => 1]]]);
        $variants = self::getContainer()->get(ImageVariants::class);
        $framed = $variants->url($m->path, 640, '4:3');
        [$r, , $b] = $this->pixel($framed, 0.5, 0.5);
        self::assertLessThan(80, $r, 'the frame is the right half');
        self::assertGreaterThan(150, $b);
        self::assertSame([640, 480], \array_slice(\getimagesize($this->root . $framed), 0, 2));
        // a ratio without a frame still follows the focal point
        [$r] = $this->pixel($variants->url($m->path, 640, '1:1'), 0.5, 0.5);
        self::assertGreaterThan(150, $r);
    }

    #[Test]
    public function onlyKnownRatiosHaveAddresses(): void
    {
        $m = $this->upload();
        $variants = self::getContainer()->get(ImageVariants::class);
        $other = $variants->url($m->path, 640, '5:2');
        $this->client->request('GET', $other);
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'not in itk_cms.images.ratios');
        foreach (['/_v/w640-r32x18/', '/_v/w640-r0x5/', '/_v/w640-r16x0/', '/_v/w321-r16x9/'] as $dir) {
            $this->client->request('GET', \str_replace('/_v/w640/', $dir, $variants->url($m->path, 640)));
            self::assertSame(404, $this->client->getResponse()->getStatusCode(), $dir);
        }
        $this->api('PATCH', 'media/' . $m->id, ['crops' => ['5:2' => ['x' => 0, 'y' => 0.2, 'w' => 1, 'h' => 0.4]]]);
        $this->client->request('GET', $other);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'a frame saved for the file makes its ratio valid');
        self::assertSame([640, 256], \array_slice(\getimagesize($this->root . $other), 0, 2));
    }

    #[Test]
    public function removingAFileRemovesItsRatioAndAvifCopies(): void
    {
        $m = $this->upload();
        $variants = self::getContainer()->get(ImageVariants::class);
        $this->client->request('GET', $variants->url($m->path, 640, '16:9'));
        $this->client->request('GET', $variants->url($m->path, 640, null, 'avif'));
        $this->client->request('GET', $variants->url($m->path, 640));
        $files = [
            (string) $variants->variantFileOf($m->path, 640, [16, 9]),
            (string) $variants->variantFileOf($m->path, 640, null, 'avif'),
            (string) $variants->variantFileOf($m->path, 640),
        ];
        foreach ($files as $f) {
            self::assertFileExists($f);
        }
        $this->api('DELETE', 'media/' . $m->id);
        $this->fileIds = [];
        foreach ($files as $f) {
            self::assertFileDoesNotExist($f);
        }
    }

    #[Test]
    public function theAvifAddressServesAnAvifFile(): void
    {
        $m = $this->upload();
        $variants = self::getContainer()->get(ImageVariants::class);
        $url = $variants->url($m->path, 640, null, 'avif');
        self::assertStringEndsWith('.avif', $url);
        $this->client->request('GET', $url);
        self::assertSame('image/avif', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(640, \getimagesize($this->root . $url)[0]);
    }

    #[Test]
    public function twigPrintsRatioUrlsAndAPictureWithAnAvifSource(): void
    {
        $m = $this->upload();
        $twig = self::getContainer()->get('twig');
        $image = ['src' => $m->path, 'alt' => 'Тест'];

        $url = $twig->createTemplate("{{ media_url(img, 960, '16:9') }}|{{ media_url(img, 960) }}|{{ media_url(img) }}")->render(['img' => $image]);
        [$ratio, $plain, $original] = \explode('|', $url);
        self::assertStringContainsString('/_v/w960-r16x9/', $ratio);
        self::assertStringContainsString('/_v/w960/', $plain);
        self::assertSame($m->path, $original);

        $html = $twig->createTemplate("{{ itk_img(img, {width: 640, ratio: '4:3', class: 'pic'}) }}")->render(['img' => $image]);
        self::assertStringStartsWith('<picture><source type="image/avif" srcset="', $html);
        self::assertStringEndsWith('></picture>', $html);
        self::assertStringContainsString('-r4x3/', $html);
        self::assertStringContainsString('.avif ', $html);
        self::assertStringContainsString('<img src="', $html);
        self::assertStringContainsString(' height="480"', $html, 'the height follows the ratio');
        self::assertStringContainsString(' class="pic"', $html);
        self::assertSame(1, \substr_count($html, '<img '));

        $plainHtml = $twig->createTemplate('{{ itk_img(img, {width: 640}) }}')->render(['img' => $image]);
        self::assertDoesNotMatchRegularExpression('~/w\d+-r\d+x\d+/~', $plainHtml, 'no ratio: the whole picture');
        self::assertStringContainsString('<picture>', $plainHtml);

        $gif = $twig->createTemplate('{{ itk_img(img, {width: 640}) }}')->render(['img' => ['src' => '/uploads/media/2026/10/x.gif']]);
        self::assertStringStartsWith('<img ', $gif, 'an address without copies is a plain img');
        self::assertStringNotContainsString('<picture>', $gif);
    }
}
