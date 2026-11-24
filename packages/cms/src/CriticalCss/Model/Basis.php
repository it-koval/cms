<?php

declare(strict_types=1);

namespace Itk\Cms\CriticalCss\Model;

use Itk\Cms\Support\Json;

/**
 * What a page's critical CSS depends on, as far as a web request can tell without a render:
 * the published snapshot of the language version (a republish of the same snapshot keeps it, any edit changes it), the build of the container
 * (in production the templates only change with a deploy) and Stylesheets::stamp() (the configured stylesheets and the generator config).
 * Settings, menus and collections are left out on purpose: they rarely change which rules style the first screen, and with them in,
 * every settings edit would regenerate every page.
 *
 * @internal
 */
final readonly class Basis
{
    private function __construct(public string $value)
    {
    }

    /** @param array<string, mixed> $publishedSnapshot */
    public static function of(array $publishedSnapshot, string $build, string $stylesheetsStamp): self
    {
        return new self(\sha1(\implode("\x1f", [\sha1(Json::encode(Json::canonical($publishedSnapshot))), $build, $stylesheetsStamp])));
    }

    public static function fromStored(string $value): self
    {
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
