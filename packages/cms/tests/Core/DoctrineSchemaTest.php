<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\PasswordReset;
use Itk\Cms\Entity\User;
use Itk\Cms\KnowledgeBase\Embedding\Space;
use Itk\Cms\Migrations\Version20261110100000;
use Itk\Cms\Security\UserManager;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** Что описано в маппинге, то и создали миграции (doctrine:schema:validate зелёный), и внешние ключи таблиц бандла работают. */
final class DoctrineSchemaTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-schema-%@example.com')->execute();
        parent::tearDown();
    }

    #[Test]
    public function theEmbeddingColumnIsAsWideAsTheSpace(): void
    {
        $type = $this->em->getConnection()->fetchOne("SELECT format_type(a.atttypid, a.atttypmod) FROM pg_attribute a WHERE a.attrelid = 'itk_kb_chunk'::regclass AND a.attname = 'embedding'");

        self::assertSame('vector(' . Space::DIMENSIONS . ')', $type, 'schema:validate cannot see the width: VectorType always declares Space::DIMENSIONS');
    }

    #[Test]
    public function doctrineSchemaValidateIsGreen(): void
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false); // run() as the console does: the filter that hides the table of the migrations is set on the console events
        $out = new BufferedOutput();

        self::assertSame(0, $application->run(new ArrayInput(['command' => 'doctrine:schema:validate']), $out), $out->fetch());
    }

    #[Test]
    public function aPasswordLinkBelongsToItsUserAndGoesWithHim(): void
    {
        $user = self::getContainer()->get(UserManager::class)->create('test-schema-link@example.com', 'Тест ключа', 'admin', 'password-for-test');
        \assert($user instanceof User);
        $link = new PasswordReset($user, \hash('sha256', 'test-schema'), PasswordReset::RESET, new \DateTimeImmutable('+1 hour'));
        $this->em->persist($link);
        $this->em->flush();
        $id = $link->id;

        self::assertSame($user, $link->user);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM itk_password_reset WHERE user_id = ?', [$user->id]));

        // the database itself drops the links of a person that is deleted (ON DELETE CASCADE), whoever deletes him
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM itk_user WHERE id = ?', [$user->id]);

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM itk_password_reset WHERE id = ?', [$id]));
    }

    #[Test]
    public function theMigrationOfTheChannelsKeepsWhatWasDeliveredAsTelegrams(): void
    {
        $db = $this->em->getConnection();
        $db->beginTransaction();
        try {
            $db->executeStatement('ALTER TABLE itk_request DROP COLUMN channels'); // the table as it was before the migration
            $insert = 'INSERT INTO itk_request (form_key, data, work, delivery, delivery_error, locale, created_at) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id';
            $ids = [];
            foreach ([['pending', null], ['sent', null], ['failed', 'Chat not found']] as [$delivery, $error]) {
                $ids[$delivery] = (int) $db->fetchOne($insert, ['call', '{}', 'new', $delivery, $error, 'ru', '2026-11-01 10:00:00']);
            }

            $migration = new Version20261110100000($db, new NullLogger());
            $migration->up(new \Doctrine\DBAL\Schema\Schema());
            foreach ($migration->getSql() as $query) {
                $db->executeStatement($query->getStatement());
            }

            $channels = static fn (int $id): mixed => \json_decode((string) $db->fetchOne('SELECT channels FROM itk_request WHERE id = ?', [$id]), true);
            self::assertSame([], $channels($ids['pending']));
            self::assertEquals(['telegram' => ['state' => 'sent', 'error' => null]], $channels($ids['sent']));
            self::assertEquals(['telegram' => ['state' => 'failed', 'error' => 'Chat not found']], $channels($ids['failed']));
            $entity = $this->em->find(\Itk\Cms\Entity\Request::class, $ids['failed']);
            self::assertInstanceOf(\Itk\Cms\Entity\Request::class, $entity);
            self::assertSame('failed', $entity->channelState('telegram'));
            self::assertSame('pending', $entity->channelState('max'));
        } finally {
            $db->rollBack();
            $this->em->clear();
        }
    }
}
