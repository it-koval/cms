<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\PageService;
use Itk\Cms\Health\Doctor;
use Itk\Cms\Health\HealthResult;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\PageRepository;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/** Real console/database integration; deliberately separate from the database-free locale checks. */
#[Group('database')]
final class DoctorLocalesCliTest extends KernelTestCase
{
    private ?Connection $db = null;
    private ?EntityManagerInterface $em = null;
    private int $initialTransactionLevel = 0;

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, itk: [
            'main_locale' => 'ru',
            'public_url' => 'http://localhost',
            'locales' => ['ru' => ['name' => 'Русский'], 'kk' => ['name' => 'Қазақша'], 'tr' => ['name' => 'Türkçe'], 'zh' => ['name' => '中文'], 'en' => ['name' => 'English']], // i18n-ignore: configured language names
        ]);
    }

    protected function tearDown(): void
    {
        try {
            // Unwind publication savepoints as well if an assertion or publish fails midway.
            while (null !== $this->db && $this->db->getTransactionNestingLevel() > $this->initialTransactionLevel) {
                $this->db->rollBack();
            }
        } finally {
            try {
                $this->em?->clear();
            } finally {
                $this->db = null;
                $this->em = null;
                parent::tearDown();
            }
        }
    }

    #[Test]
    public function ciFailsForTheMissingMainHomeAndSucceedsWithOnlyMissingSecondaryHomes(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $this->db = $c->get(Connection::class);
        $this->em = $c->get(EntityManagerInterface::class);
        $this->initialTransactionLevel = $this->db->getTransactionNestingLevel();
        $this->db->beginTransaction();
        $home = $c->get(PageRepository::class)->findOneBy(['template' => 'home']);
        self::assertNotNull($home, 'itk:install creates the home fixture');
        $homeId = $home->id;
        $this->db->executeStatement(
            "UPDATE itk_page_translation SET status = 'draft', published = NULL, published_url = NULL, published_at = NULL
             WHERE (locale = 'ru' AND published_url = '/') OR (locale IN (:secondary) AND published_url = '/' || locale)",
            ['secondary' => ['kk', 'tr', 'zh', 'en']],
            ['secondary' => ArrayParameterType::STRING],
        );
        $this->db->executeStatement(
            "UPDATE itk_page_translation SET status = 'draft', published = NULL, published_url = NULL, published_at = NULL,
             draft = jsonb_set(draft, '{url}', '\"/\"'::jsonb) WHERE page_id = :home AND locale = 'ru'",
            ['home' => $homeId],
        );
        $this->em->clear();
        $tester = new CommandTester((new Application(self::$kernel))->find('itk:doctor'));

        $tester->execute(['--ci' => true]);
        self::assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString(I18n::t('ops.doctor_home_unpublished'), $tester->getDisplay());

        $c->get(PageService::class)->publish($homeId, 'ru');
        $tester->execute(['--ci' => true]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $lines = \array_column($c->get(Doctor::class)->run(), null, 'name');
        self::assertSame(HealthResult::OK, $lines[I18n::t('ops.doctor_name_locale_home', ['locale' => 'ru'])]['status']);
        foreach (['kk', 'tr', 'zh', 'en'] as $locale) {
            $name = I18n::t('ops.doctor_name_locale_home', ['locale' => $locale]);
            self::assertSame(HealthResult::WARN, $lines[$name]['status']);
            self::assertStringContainsString($name, $tester->getDisplay());
            self::assertStringContainsString('/' . $locale, $lines[$name]['message']);
        }
    }
}
