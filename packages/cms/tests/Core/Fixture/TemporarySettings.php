<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Content\SettingsService;

/** Shared settings for one test: setting() remembers what was there, restoreSettings() puts it back or removes the row the test made. */
trait TemporarySettings
{
    /** @var array<string, array{0: bool, 1: mixed}> */
    private array $settingsBefore = [];

    private function setting(string $key, mixed $value): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        $this->settingsBefore[$key] ??= [$settings->isSet($key), $settings->get($key)];
        $settings->set($key, $value);
    }

    private function restoreSettings(): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        foreach ($this->settingsBefore as $key => [$wasSet, $value]) {
            if ($wasSet) {
                $settings->set($key, $value);
            } else {
                $settings->remove($key);
            }
        }
        $this->settingsBefore = [];
    }
}
