<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\I18n\I18n;
use Itk\Cms\Support\Url;

/** @internal Page-level input contract shared by PHP and MCP writers. */
final class PageInputRules
{
    public const TITLE_SCHEMA = ['type' => 'string'];
    public const ADDRESS_SCHEMA = ['type' => 'string', 'maxLength' => 255];

    public static function titleProblem(string $title): ?string
    {
        return '' === \trim($title) ? I18n::t('content.page_title_required') : null;
    }

    public static function addressProblem(string $url): ?string
    {
        return Url::isLocalPath($url) && \mb_strlen($url) <= self::ADDRESS_SCHEMA['maxLength'] && false === \strpbrk($url, '?#\\')
            ? null : I18n::t('content.page_url_invalid');
    }
}
