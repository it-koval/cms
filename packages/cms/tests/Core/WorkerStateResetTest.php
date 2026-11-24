<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentReader;
use Itk\Cms\Content\LiveUrlIndex;
use Itk\Cms\Content\SeoResolver;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Entity\Role;
use Itk\Cms\Entity\SeoRule;
use Itk\Cms\Entity\Setting;
use Itk\Cms\Mcp\McpSettings;
use Itk\Cms\Request\Max\MaxApiSender;
use Itk\Cms\Security\RoleResolver;
use Itk\Cms\Twig\ItkRuntime;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\ResetServicesListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Symfony\Contracts\Service\ResetInterface;

final class WorkerStateResetTest extends KernelTestCase
{
    #[Test]
    public function theSecondMessageSeesExternalSettingsRolesSeoAndLiveUrlChanges(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $db = $container->get(Connection::class);
        $other = DriverManager::getConnection($db->getParams());
        $key = 'f2_' . \bin2hex(\random_bytes(6));
        $path = '/' . $key;
        $page = new Page();
        $translation = new PageTranslation($page, 'ru');
        $translation->published = ['title' => 'First', 'url' => $path, 'fields' => [], 'seo' => []];
        $translation->draft = $translation->published;
        $translation->publishedUrl = $path;
        $translation->publishedAt = new \DateTimeImmutable();
        $translation->status = PageTranslation::PUBLISHED;
        $page->translations->set('ru', $translation);
        $role = new Role($key, 'F2 worker role');
        $role->perms = ['pages' => ['view']];
        $rule = new SeoRule($path);
        $rule->title = 'First SEO';
        foreach ([$page, $role, $rule, new Setting($key, '*', 'First')] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $em->clear();
        $resolver = $container->get(RoleResolver::class);
        $seen = [];
        $cache = new \ReflectionProperty(RoleResolver::class, 'cache');
        $handle = static function () use ($container, $em, $resolver, $cache, &$seen, $key, $path, $page, $other, $translation, $rule): void {
            $cacheBefore = \count($cache->getValue($resolver));
            $rights = $resolver->resolve($em->find(Role::class, $key));
            $seen[] = [
                'cachedRolesAtStart' => $cacheBefore,
                'setting' => $container->get(SettingsService::class)->stored($key),
                'canEdit' => RoleResolver::allows($rights, 'pages', 'edit'),
                'title' => $container->get(ContentReader::class)->page($page->id, 'ru')['title'],
                'newUrl' => $container->get(LiveUrlIndex::class)->has('ru', $path . '-changed'),
                'seo' => $container->get(SeoResolver::class)->forRequest(Request::create($path), 'ru')?->title,
            ];
            if (1 === \count($seen)) {
                $other->executeStatement('UPDATE itk_setting SET setting_value = ? WHERE setting_key = ?', [\json_encode('Second'), $key]);
                $other->executeStatement('UPDATE itk_role SET perms = ? WHERE role_key = ?', [\json_encode(['pages' => ['view', 'edit']]), $key]);
                $other->executeStatement('UPDATE itk_page_translation SET published_url = ?, published = ? WHERE id = ?', [$path . '-changed', \json_encode(['title' => 'Second', 'url' => $path . '-changed', 'fields' => [], 'seo' => []]), $translation->id]);
                $other->executeStatement('UPDATE itk_seo_rule SET title = ? WHERE id = ?', ['Second SEO', $rule->id]);
            }
        };
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([F2WorkerProbe::class => [$handle]]))]);
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new F2WorkerProbe()));
        $transport->send(new Envelope(new F2WorkerProbe()));
        $events = $container->get('event_dispatcher');
        $reset = new ResetServicesListener($container->get('services_resetter'));
        $events->addSubscriber($reset); // same subscriber messenger:consume installs for a real worker
        $stop = static function (WorkerRunningEvent $event) use (&$seen): void {
            if ($event->isWorkerIdle() || \count($seen) >= 2) {
                $event->getWorker()->stop();
            }
        };
        $events->addListener(WorkerRunningEvent::class, $stop);
        try {
            (new Worker(['f2' => $transport], $bus, $events))->run(['sleep' => 0]);
            self::assertSame([
                ['cachedRolesAtStart' => 0, 'setting' => 'First', 'canEdit' => false, 'title' => 'First', 'newUrl' => false, 'seo' => 'First SEO'],
                ['cachedRolesAtStart' => 0, 'setting' => 'Second', 'canEdit' => true, 'title' => 'Second', 'newUrl' => true, 'seo' => 'Second SEO'],
            ], $seen);
            self::assertCount(2, $transport->getAcknowledged());
            foreach ([SettingsService::class, LiveUrlIndex::class, ContentReader::class, SeoResolver::class, McpSettings::class, RoleResolver::class, ItkRuntime::class, MaxApiSender::class] as $class) {
                self::assertTrue(\is_a($class, ResetInterface::class, true), $class . ' participates in kernel.reset');
            }
        } finally {
            $events->removeSubscriber($reset);
            $events->removeListener(WorkerRunningEvent::class, $stop);
            $em->clear();
            $other->executeStatement('DELETE FROM itk_page WHERE id = ?', [$page->id]);
            $other->executeStatement('DELETE FROM itk_role WHERE role_key = ?', [$key]);
            $other->executeStatement('DELETE FROM itk_seo_rule WHERE id = ?', [$rule->id]);
            $other->executeStatement('DELETE FROM itk_setting WHERE setting_key = ?', [$key]);
            $other->close();
        }
    }
}

final class F2WorkerProbe
{
}
