<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\MediaFile;
use Itk\Cms\Entity\Request;
use Itk\Cms\Entity\User;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Media\ImageVariants;
use Itk\Cms\Tests\Core\Fixture\McpSupport;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ListPaginationTest extends WebTestCase
{
    use McpSupport;

    private KernelBrowser $client;
    private User $user;
    private string $key;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->user = $this->mcpUser(tag: 'pagination');
        $this->client->loginUser($this->user, 'itk_admin');
        $this->key = \bin2hex(\random_bytes(6));
    }

    protected function tearDown(): void
    {
        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $db->executeStatement('DELETE FROM itk_media_file WHERE alt = ?', [$this->key]);
        $db->executeStatement('DELETE FROM itk_request WHERE form_key = ?', [$this->key]);
        $db->executeStatement('DELETE FROM itk_user WHERE id = ?', [$this->user->id]);
        parent::tearDown();
    }

    #[Test]
    public function adminPagesRequestsInTheDatabaseWithTheLanguageAndSearchFilters(): void
    {
        $ids = $this->requests();
        $path = 'requests?form=' . $this->key . '&locale=kk&q=Astana';
        $first = $this->get($path);
        $second = $this->get($path . '&page=2');
        $empty = $this->get($path . '&page=300');

        self::assertSame([113, 1, 50], [$first['total'], $first['page'], $first['perPage']]);
        self::assertSame(\array_slice($ids, 0, 50), \array_column($first['items'], 'id'));
        self::assertSame(\array_slice($ids, 50, 50), \array_column($second['items'], 'id'));
        self::assertSame([], $empty['items']);
        self::assertSame(100, $this->get($path . '&perPage=9999')['perPage']);
    }

    #[Test]
    public function dashboardLimitsThePreviewAndKeepsTheTotal(): void
    {
        $before = $this->get('dashboard')['newRequestCount'];
        $ids = $this->requests(new \DateTimeImmutable('+2 minutes'));
        $answer = $this->get('dashboard');

        self::assertSame($before + 114, $answer['newRequestCount']);
        self::assertCount(8, $answer['newRequests']);
        self::assertSame(\array_slice($ids, 0, 7), \array_slice(\array_column($answer['newRequests'], 'id'), 1));
    }

    #[Test]
    public function adminMediaPagesUseStoredDimensionsAndFilterBeforeSlicing(): void
    {
        $ids = $this->media();
        $variants = self::getContainer()->get(ImageVariants::class);
        $sizes = new \ReflectionProperty($variants, 'sizes');
        $path = 'media?folder=none&type=img&q=' . $this->key . '&perPage=25';
        $first = $this->get($path);
        $second = $this->get($path . '&page=2');

        self::assertSame([113, 1, 25], [$first['total'], $first['page'], $first['perPage']]);
        self::assertSame(\array_slice($ids, 0, 25), \array_column($first['items'], 'id'));
        self::assertSame(\array_slice($ids, 25, 25), \array_column($second['items'], 'id'));
        self::assertSame(120, $first['items'][0]['w']);
        self::assertSame([], $sizes->getValue($variants), 'No original image path was opened to compute a thumbnail URL.');
    }

    #[Test]
    public function mediaSearchKeepsLiteralFilenameCharacters(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $matching = new MediaFile('/uploads/media/' . $this->key . '-one.pdf', $this->key . ' 100%_ready.pdf', 'application/pdf');
        $other = new MediaFile('/uploads/media/' . $this->key . '-two.pdf', $this->key . ' 100Axready.pdf', 'application/pdf');
        foreach ([$matching, $other] as $file) {
            $file->alt = $this->key;
            $em->persist($file);
        }
        $em->flush();

        $page = $this->get('media?' . \http_build_query(['q' => $this->key . ' 100%_', 'type' => 'pdf']));

        self::assertSame(1, $page['total']);
        self::assertSame([$matching->id], \array_column($page['items'], 'id'));
    }

    #[Test]
    public function mcpOnlyHydratesTheRequestedPageAndKeepsTheOffsetContract(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $key = $this->mcpKey($this->user, [McpScopes::REQUESTS, McpScopes::READ]);
        $requests = $this->requests();
        $media = $this->media();
        $em->clear();
        $ctx = $this->mcpContext($key['token']);
        $args = ['limit' => 15, 'offset' => 17];
        $req = $this->mcpCall($ctx, 'requests_list', $args + ['form' => $this->key, 'q' => 'Astana']);
        $img = $this->mcpCall($ctx, 'media_search', $args + ['type' => 'img', 'q' => $this->key]);
        self::assertTrue($req['ok'], $req['text']);
        self::assertTrue($img['ok'], $img['text']);

        foreach ([[$req['data'], $requests], [$img['data'], $media]] as [$page, $ids]) {
            self::assertSame([113, 17, 15, true], [$page['total'], $page['offset'], $page['limit'], $page['hasMore']]);
            self::assertSame(\array_slice($ids, 17, 15), \array_column($page['items'], 'id'));
        }
        $hydrated = $em->getUnitOfWork()->getIdentityMap();
        self::assertCount(15, $hydrated[Request::class]);
        self::assertCount(15, $hydrated[MediaFile::class]);
    }

    /** @return list<int> */
    private function requests(?\DateTimeImmutable $created = null): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $rows = [];
        for ($i = 0; $i < 114; ++$i) {
            $r = new Request($this->key);
            $r->locale = $i < 113 ? 'kk' : 'ru';
            $r->city = $i < 113 ? 'Astana' : 'Taraz';
            $r->createdAt = $created ?? new \DateTimeImmutable('2026-04-15T14:10:00');
            $em->persist($r);
            if ($i < 113) {
                $rows[] = $r;
            }
        }
        $em->flush();

        return \array_reverse(\array_column($rows, 'id'));
    }

    /** @return list<string> */
    private function media(): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $ids = [];
        for ($i = 0; $i < 114; ++$i) {
            $m = new MediaFile('/uploads/media/' . $this->key . '-' . $i . '.png', 'Picture ' . $i, $i < 113 ? 'image/png' : 'application/pdf');
            $m->createdAt = new \DateTimeImmutable('2026-04-15T14:10:00');
            $m->alt = $this->key;
            $m->width = 120;
            $m->height = 80;
            $em->persist($m);
            if ($i < 113) {
                $ids[] = $m->id;
            }
        }
        $em->flush();
        \rsort($ids, \SORT_STRING);

        return $ids;
    }

    /** @return array<string, mixed> */
    private function get(string $path): array
    {
        $this->client->request('GET', '/admin/api/' . $path);
        self::assertResponseIsSuccessful();

        return \json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
