<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\Entity\Page;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\Support\Url;

/** @internal */
final class PageVersions
{
    public function __construct(
        private readonly ContentLocales $locales,
        private readonly IndexablePages $indexable,
    ) {
    }

    /** @return list<array{code: string, name: string, url: string, path: string, noindex: bool}> */
    public function live(Page $page): array
    {
        $live = [];
        foreach ($page->translations as $translation) {
            if ($translation->isLive() && $this->locales->has($translation->locale)) {
                $live[$translation->locale] = $translation->published;
            }
        }

        $versions = [];
        foreach ($this->locales->codes() as $code) {
            if (!isset($live[$code])) {
                continue;
            }
            $published = $live[$code];
            $path = (string) ($published['url'] ?? '');
            $versions[] = [
                'code' => $code,
                'name' => $this->locales->name($code),
                'url' => Url::encodePath(Url::spelling($path)),
                'path' => $path,
                'noindex' => $this->indexable->noindex((array) ($published['seo'] ?? []), $path, $code),
            ];
        }

        return $versions;
    }
}
