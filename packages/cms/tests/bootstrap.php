<?php

declare(strict_types=1);

require \dirname(__DIR__) . '/vendor/autoload.php';

// tests without a kernel speak the language of the test site (admin_locale: ru in TestKernel)
Itk\Cms\I18n\I18n::useLocale('ru');
