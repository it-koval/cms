<?php

declare(strict_types=1);

namespace Itk\Cms\Resource;

/** A group of site settings (one block of «Настройки»). Read in code with Itk\Cms\Settings\Settings, in Twig with itk_settings('key'). */
abstract class SettingsResource implements ResourceInterface
{
    public function description(): string
    {
        return '';
    }
}
