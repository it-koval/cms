<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Tests\App\TestKernel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/** Sets the toggle «IndexNow» and the site address for one test and puts both back (or removes them) afterwards; the kernel has an HTTP client, which the test site does not get by itself. */
trait IndexNowSettings
{
    /** @var array<string, array{0: bool, 1: mixed}> */
    private array $settingsBefore = [];

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', ['http_client' => ['enabled' => true]]);
    }

    private function setting(string $key, mixed $value): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        $this->settingsBefore[$key] ??= [$settings->isSet($key), $settings->get($key)];
        $settings->set($key, $value);
    }

    private function forgetSubmission(): void
    {
        self::getContainer()->get(CacheItemPoolInterface::class)->deleteItem('itk_indexnow_last');
    }

    private function restoreSettings(): void
    {
        $c = self::getContainer();
        $this->forgetSubmission();
        foreach ($this->settingsBefore as $key => [$wasSet, $value]) {
            if ($wasSet) {
                $c->get(SettingsService::class)->set($key, $value);
            } else {
                $c->get(EntityManagerInterface::class)->createQuery('DELETE FROM Itk\Cms\Entity\Setting s WHERE s.key = :k')->setParameter('k', $key)->execute();
            }
        }
        $this->settingsBefore = [];
    }
}
