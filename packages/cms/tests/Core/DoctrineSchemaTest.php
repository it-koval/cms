<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\PasswordReset;
use Itk\Cms\Entity\User;
use Itk\Cms\KnowledgeBase\Embedding\Space;
use Itk\Cms\Security\UserManager;
use PHPUnit\Framework\Attributes\Test;
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
}
