<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Tests\App\TestKernel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/** Sets the toggle «IndexNow» and the site address for one test and puts both back (or removes them) afterwards; the kernel has an HTTP client, which the test site does not get by itself. */
trait IndexNowSettings
{
    use TemporarySettings {
        restoreSettings as private restoreSettingValues;
    }

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', ['http_client' => ['enabled' => true]]);
    }

    private function forgetSubmission(): void
    {
        self::getContainer()->get(CacheItemPoolInterface::class)->deleteItem('itk_indexnow_last');
    }

    private function restoreSettings(): void
    {
        $this->forgetSubmission();
        $this->restoreSettingValues();
    }
}
