<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Bots\BotSettings;
use Itk\Cms\Content\LiveUrlIndex;
use Itk\Cms\Content\PageService;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Setting;
use Itk\Cms\Mcp\McpSettings;
use Itk\Cms\Security\TwoFactor\SecretBox;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A worker (messenger:consume, itk:mcp) lives for hours: what a service remembered from the database must be forgotten between two messages (kernel.reset),
 * or the worker sends a request to a chat that was removed from the settings an hour ago.
 */
final class ServiceResetTest extends KernelTestCase
{
    private ?string $pageId = null;
    /** @var array<string, false|string> setting => its stored JSON before the test (false = no row) */
    private array $saved = [];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        if (null !== $this->pageId) {
            self::getContainer()->get(PageService::class)->remove($this->pageId);
        }
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Redirect r WHERE r.fromPath LIKE :p')->setParameter('p', '/test-reset%')->execute();
        $db = self::getContainer()->get(Connection::class);
        foreach ($this->saved as $key => $before) {
            $db->executeStatement('DELETE FROM itk_setting WHERE setting_key = ? AND locale = ?', [$key, Setting::ALL]);
            if (false !== $before) {
                $db->executeStatement('INSERT INTO itk_setting (setting_key, locale, setting_value) VALUES (?, ?, ?)', [$key, Setting::ALL, $before]);
            }
        }
        parent::tearDown();
    }

    /** What the kernel does between two messages of a worker (and between two requests of a long-running server). */
    private function betweenTwoMessages(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear(); // DoctrineClearEntityManagerWorkerSubscriber
        self::getContainer()->get('services_resetter')->reset();
    }

    /** Writes a setting the way another process does: behind the back of the services of this one. */
    private function writeBehindTheBack(string $key, mixed $value): void
    {
        $db = self::getContainer()->get(Connection::class);
        $this->saved[$key] ??= $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', [$key, Setting::ALL]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $db->executeStatement('DELETE FROM itk_setting WHERE setting_key = ? AND locale = ?', [$key, Setting::ALL]);
        $db->executeStatement('INSERT INTO itk_setting (setting_key, locale, setting_value) VALUES (?, ?, ?)', [$key, Setting::ALL, \json_encode($value)]);
    }

    #[Test]
    public function theServicesThatRememberTheDatabaseAreReset(): void
    {
        foreach ([SettingsService::class, LiveUrlIndex::class, McpSettings::class] as $class) {
            self::assertInstanceOf(ResetInterface::class, self::getContainer()->get($class), $class);
        }
    }

    #[Test]
    public function aWorkerDoesNotSendARequestToAChatThatWasRemovedFromTheSettings(): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        $this->writeBehindTheBack('tgRoutes', ['*' => '-1001111111111']);
        self::assertSame(['-1001111111111'], $settings->chatIdsFor('call'), 'the worker has read the chat');

        $this->writeBehindTheBack('tgRoutes', ['*' => '-1002222222222']); // the administrator changed the chat in the admin
        self::assertSame(['-1001111111111'], $settings->chatIdsFor('call'), 'without a reset the worker still has the old one');

        $this->betweenTwoMessages();

        self::assertSame(['-1002222222222'], self::getContainer()->get(SettingsService::class)->chatIdsFor('call'));
        $this->writeBehindTheBack('tgOn', false);
        $this->betweenTwoMessages();
        self::assertFalse(self::getContainer()->get(SettingsService::class)->isTelegramOn());
    }

    #[Test]
    public function aWorkerDoesNotSendARequestToAMaxChatThatWasRemovedFromTheSettings(): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        $this->writeBehindTheBack('maxRoutes', ['*' => '-70001']);
        self::assertSame(['-70001'], $settings->maxChatIdsFor('call'));

        $this->writeBehindTheBack('maxRoutes', ['*' => '-70002']);
        self::assertSame(['-70001'], $settings->maxChatIdsFor('call'), 'without a reset the worker still has the old one');

        $this->betweenTwoMessages();

        self::assertSame(['-70002'], self::getContainer()->get(SettingsService::class)->maxChatIdsFor('call'));
        $this->writeBehindTheBack('maxOn', false);
        $this->betweenTwoMessages();
        self::assertFalse(self::getContainer()->get(SettingsService::class)->isMaxOn());
    }

    #[Test]
    public function aWorkerReadsNewBotTokensAfterTheMessageReset(): void
    {
        $bots = self::getContainer()->get(BotSettings::class);
        $box = self::getContainer()->get(SecretBox::class);
        foreach (['telegram' => 'itk:telegramBot', 'max' => 'itk:maxBot'] as $channel => $key) {
            $this->writeBehindTheBack($key, ['token' => $box->seal('first-token')]);
            self::assertSame('first-token', $bots->token($channel));
            $this->writeBehindTheBack($key, ['token' => $box->seal('replacement-token')]);
            $this->betweenTwoMessages();
            self::assertSame('replacement-token', $bots->token($channel));
        }
    }

    #[Test]
    public function theMcpSwitchIsReadAgain(): void
    {
        $mcp = self::getContainer()->get(McpSettings::class);
        $this->writeBehindTheBack(McpSettings::KEY, false);
        self::assertFalse($mcp->isEnabled());

        $this->writeBehindTheBack(McpSettings::KEY, true);
        self::assertFalse($mcp->isEnabled(), 'remembered');
        $this->betweenTwoMessages();

        self::assertTrue(self::getContainer()->get(McpSettings::class)->isEnabled());
    }

    #[Test]
    public function theIndexOfLivePagesIsReadAgain(): void
    {
        $index = self::getContainer()->get(LiveUrlIndex::class);
        self::assertFalse($index->has('ru', '/test-reset-live'));

        $pages = self::getContainer()->get(PageService::class);
        $page = $pages->create(['template' => 'default', 'title' => 'Сброс', 'url' => '/test-reset-live']);
        $this->pageId = $page['id'];
        $pages->publish($page['id']);
        self::assertFalse($index->has('ru', '/test-reset-live'), 'remembered');
        $this->betweenTwoMessages();

        self::assertTrue(self::getContainer()->get(LiveUrlIndex::class)->has('ru', '/test-reset-live'));
    }
}
