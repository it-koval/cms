<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\PageService;
use Itk\Cms\Entity\MediaFile;
use Itk\Cms\Entity\User;
use Itk\Cms\Media\MediaService;
use Itk\Cms\Security\Viewer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class MediaUsageAudienceTest extends KernelTestCase
{
    #[Test]
    public function usageRequiresAnExplicitAudienceAndOnlyReturnsReadableMatches(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $c->get('security.token_storage')->setToken(new UsernamePasswordToken(new User('fixture-admin@example.test', 'Fixture admin', 'admin'), 'itk_admin', []));
        $em = $c->get(EntityManagerInterface::class);
        $pages = $c->get(PageService::class);
        $media = $c->get(MediaService::class);
        $unique = \bin2hex(\random_bytes(6));
        $file = new MediaFile('/uploads/media/f1-audience-' . $unique . '.pdf', 'fixture.pdf', 'application/pdf');
        $em->persist($file);
        $em->flush();
        $ids = [];
        try {
            foreach (['summary', 'internal'] as $field) {
                $page = $pages->create(['template' => 'default', 'title' => 'F1 audience ' . $field, 'url' => '/f1-audience-' . $field . '-' . $unique,
                    'sections' => [['id' => 'f1-section-' . $unique, 'type' => 'notes', 'data' => [$field => $file->path]]],
                ]);
                $ids[] = $page['id'];
            }
            $viewer = Viewer::of(new User('fixture-' . $unique . '@example.test', 'Fixture', 'editor'), ['perms' => ['pages' => ['view']], 'templates' => ['default']]);
            self::assertSame(['/pages/' . $ids[0]], \array_column($media->usage($file, $viewer), 'to'));
            $denied = Viewer::of(new User('denied-' . $unique . '@example.test', 'Denied', 'editor'), ['perms' => [], 'templates' => null]);
            self::assertSame([], $media->usage($file, $denied));
            self::assertEqualsCanonicalizing(\array_map(static fn (string $id): string => '/pages/' . $id, $ids), \array_column($media->usage($file, Viewer::system()), 'to'));
            try {
                $media->usage($file);
                self::fail('usage without an audience must be rejected');
            } catch (\ArgumentCountError) {
            }
            self::assertSame([], $media->present($file)['used']);
            try {
                $media->present($file, true);
                self::fail('present with usage must also require an audience');
            } catch (\InvalidArgumentException) {
            }
        } finally {
            foreach (\array_reverse($ids) as $id) {
                $pages->remove($id);
            }
            $em->remove($em->getReference(MediaFile::class, $file->id));
            $em->flush();
            $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceId IN (:ids)')->setParameter('ids', [...$ids, $file->id])->execute();
            $c->get('security.token_storage')->setToken(null);
        }
    }
}
