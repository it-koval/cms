<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\Schema\Schema;
use Itk\Cms\Schema\Template;

/**
 * The page's one <h1>: the headline of the FIRST VISIBLE section whose type is in the group «Первый экран».
 * A template with h1(field) owns the H1 instead (an article's title): then no section carries it.
 *
 * @internal
 */
final class H1Rule
{
    public function __construct(private readonly Schema $schema)
    {
    }

    /**
     * @param list<array<string, mixed>> $sections
     */
    public function pageH1Id(array $sections, ?Template $template = null): ?string
    {
        if (null !== $template && null !== $template->h1Field()) {
            return null;
        }
        foreach ($sections as $s) {
            if (!empty($s['hidden'])) {
                continue;
            }
            $type = $this->schema->section((string) ($s['type'] ?? ''));
            if (null !== $type && $type->isFirstScreen()) {
                return (string) $s['id'];
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $sections */
    public function hasPageH1(array $sections, ?Template $template = null): bool
    {
        return (null !== $template && null !== $template->h1Field()) || null !== $this->pageH1Id($sections);
    }
}
