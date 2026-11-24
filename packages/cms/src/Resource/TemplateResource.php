<?php

declare(strict_types=1);

namespace Itk\Cms\Resource;

use Itk\Cms\Schema\Template;

/**
 * A page template. key(), title(), fields() are the fields of the page besides its sections; the rest is what Template::* says,
 * as overridable methods (the defaults change nothing) or all at once in configure(Template $t).
 * A key of a core template ('blog_article') changes that template instead of adding a new one.
 */
abstract class TemplateResource implements ResourceInterface
{
    use ResourceHooks;

    /** One sentence for the client. */
    public function about(): string
    {
        return '';
    }

    /**
     * Page templates this one may stand under (empty = anywhere).
     *
     * @return list<string>
     */
    public function parents(): array
    {
        return [];
    }

    /** Only at the top level. */
    public function topLevel(): bool
    {
        return false;
    }

    /** Only one page with this template on the site. */
    public function single(): bool
    {
        return false;
    }

    /** The home page. */
    public function root(): bool
    {
        return false;
    }

    /** Nothing can be put under a page with this template. */
    public function leaf(): bool
    {
        return false;
    }

    /** 'all' = sections can be added, false = the page has no sections. */
    public function sections(): string|false
    {
        return 'all';
    }

    /**
     * What the page lists: ['of' => ['product'], 'label' => 'Товары', 'onlyIf' => 'catalog'] or ['collection' => 'tours', 'label' => 'Поездки'].
     *
     * @return array<string, mixed>|null
     */
    public function listing(): ?array
    {
        return null;
    }

    /** The template field that is the H1 of the page. */
    public function h1(): ?string
    {
        return null;
    }

    /** Label of the quick link in the admin menu. */
    public function shortcut(): ?string
    {
        return null;
    }

    /**
     * Keys of the fields a list card shows: ['image' => 'cover', 'title' => 'title', 'text' => 'excerpt', 'date' => 'date'].
     *
     * @return array<string, string>|null
     */
    public function card(): ?array
    {
        return null;
    }

    /**
     * Menu areas the layout of this template shows: ['top' => ['header'], 'bottom' => ['footer']].
     *
     * @return array{top?: list<string>, bottom?: list<string>}|null
     */
    public function areas(): ?array
    {
        return null;
    }

    /** Last word on the template: everything the methods above cannot say. */
    public function configure(Template $template): void
    {
    }
}
