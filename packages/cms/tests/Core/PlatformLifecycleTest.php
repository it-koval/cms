<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\MediaFile;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\Twig\ItkRuntime;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Environment;

final class PlatformLifecycleTest extends KernelTestCase
{
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', ['default_locale' => 'ru'], [], ['admin_locale' => 'en', 'strict' => false]);
    }

    #[Test]
    public function theApplicationLanguageSurvivesConsoleWorkerAndPersonalLanguage(): void
    {
        self::bootKernel();
        self::assertSame('ru', I18n::locale(), 'console uses framework.default_locale');
        $locale = self::getContainer()->get(AdminLocale::class);
        self::assertSame('en', $locale->default(), 'the admin keeps its configured language');
        self::assertSame('kk', $locale->run('kk', static fn (): string => I18n::locale()));
        self::assertSame('ru', I18n::locale(), 'personal mail restores the application language');
        self::getContainer()->get(LocaleSwitcher::class)->setLocale('kk');
        self::getContainer()->get('event_dispatcher')->dispatch(new WorkerMessageReceivedEvent(new Envelope(new \stdClass()), 'async'));
        self::assertSame('ru', I18n::locale(), 'a worker starts from the application language');
        self::getContainer()->get('services_resetter')->reset();
        self::assertSame('ru', I18n::locale(), 'reset preserves the application default');
    }

    #[Test]
    public function theRussianConsoleAndEnglishAdminKeepTheirOwnLanguages(): void
    {
        $kernel = self::bootKernel();
        $command = new CommandTester((new Application($kernel))->find('itk:schema:lint'));
        self::assertSame(0, $command->execute([]));
        self::assertStringContainsString('Схема в порядке.', $command->getDisplay());
        $client = new KernelBrowser($kernel);
        $client->disableReboot();
        $client->request('GET', '/admin/login');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('<html lang="en">', (string) $client->getResponse()->getContent());
        self::assertSame('ru', I18n::locale(), 'the admin language ends with its request');
    }

    #[Test]
    public function theStandardBundleOverrideWinsOverTheFallbackLayout(): void
    {
        self::bootKernel();
        $html = self::getContainer()->get(Environment::class)->render('@ItkCms/layout.html.twig');
        self::assertStringContainsString('f2-site-layout-override', $html);
        self::assertStringContainsString('<!doctype html>', $html, 'the override can extend the original using @!ItkCms');
    }

    #[Test]
    public function aSavedImageFrameChangedByAnotherRequestIsReadAfterReset(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $path = '/uploads/media/f2-frame-' . \bin2hex(\random_bytes(6)) . '.jpg';
        $media = new MediaFile($path, 'F2 frame', 'image/jpeg');
        $em->persist($media);
        $em->flush();
        $runtime = $container->get(ItkRuntime::class);
        $before = $runtime->mediaUrl($path, 640, '7:5');
        $other = DriverManager::getConnection($container->get(Connection::class)->getParams());
        try {
            $other->executeStatement('UPDATE itk_media_file SET crops = ? WHERE id = ?', [\json_encode(['7:5' => ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]]), $media->id]);
            self::assertSame($before, $runtime->mediaUrl($path, 640, '7:5'), 'the current request reuses its cached frame lookup');
            $em->clear();
            $container->get('services_resetter')->reset();
            $after = $runtime->mediaUrl($path, 640, '7:5');
            self::assertNotSame($before, $after, 'the next request sees the newly saved frame');
            self::assertStringContainsString('7x5', $after);
        } finally {
            $em->clear();
            $other->executeStatement('DELETE FROM itk_media_file WHERE id = ?', [$media->id]);
            $other->close();
        }
    }

    #[Test]
    public function aTwigWarningIsReportedAgainInTheNextRequest(): void
    {
        self::bootKernel();
        $runtime = self::getContainer()->get(ItkRuntime::class);
        $runtime->field(['template' => 'default', 'fields' => []], 'f2_unknown_field');
        $logger = self::getContainer()->get('logger');
        $warnings = static fn (): array => \array_filter($logger->records, static fn (array $r): bool => 'warning' === $r['level'] && \str_contains($r['message'], 'f2_unknown_field'));
        self::assertCount(1, $warnings());
        $runtime->field(['template' => 'default', 'fields' => []], 'f2_unknown_field');
        self::assertCount(1, $warnings(), 'deduplicated in the same request');
        self::getContainer()->get('services_resetter')->reset();
        $runtime->field(['template' => 'default', 'fields' => []], 'f2_unknown_field');
        self::assertCount(2, $warnings(), 'the next request has its own diagnostics');
    }
}
