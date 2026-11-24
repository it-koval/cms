<?php

declare(strict_types=1);

namespace Itk\Cms\Schema;

use Itk\Cms\Field\FieldInterface;
use Itk\Cms\Field\FormFields;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Resource\TemplateResource;
use Itk\Cms\Security\Viewer;

/**
 * A page template: which fields a page has besides sections, where it may stand, what it lists, where its H1 comes from.
 * Mirrors admin/src/schema/templates.js.
 */
final class Template
{
    private string $about = '';
    private bool $core = false;
    private bool $authors = false;
    private bool $single = false;
    private bool $root = false;
    /** @var 'any'|list<string>|null null = top level only */
    private string|array|null $parents = 'any';
    /** @var list<FieldInterface> */
    private array $fields = [];
    private string|false $sections = 'all';
    /** @var array<string, mixed>|null */
    private ?array $listing = null;
    private ?string $h1Field = null;
    private bool $leaf = false;
    private ?string $shortcut = null;
    /** @var array<string, string>|null */
    private ?array $card = null;
    /** @var 'layout'|array{top: list<string>, bottom: list<string>} */
    private string|array $areas = 'layout';
    private ?TemplateResource $resource = null;
    /** @var array<string, string> catalogue keys of the texts of the bundle itself (name, about, listing, shortcut), read in the language of the person who asks */
    private array $keys = [];

    public function __construct(private readonly string $key, private string $name)
    {
    }

    public function name(string $name): self
    {
        $this->name = $name;
        unset($this->keys['name']);

        return $this;
    }

    /** One sentence for the client. */
    public function about(string $about): self
    {
        $this->about = $about;
        unset($this->keys['about']);

        return $this;
    }

    /**
     * The texts of a template the bundle itself writes, by catalogue key: the schema is built before any request has chosen a language,
     * so the text is looked up when it is read, not when it is built. A name(), about(), listing() or shortcut() called later wins.
     */
    public function texts(?string $name = null, ?string $about = null, ?string $listing = null, ?string $shortcut = null): self
    {
        foreach (['name' => $name, 'about' => $about, 'listing' => $listing, 'shortcut' => $shortcut] as $part => $key) {
            if (null !== $key) {
                $this->keys[$part] = $key;
            }
        }

        return $this;
    }

    private function text(string $part, ?string $plain): ?string
    {
        return isset($this->keys[$part]) ? I18n::t($this->keys[$part]) : $plain;
    }

    public function markCore(): self
    {
        $this->core = true;

        return $this;
    }

    /** Explicitly permits the built-in Author role to edit this template. */
    public function forAuthors(bool $allow = true): self
    {
        $this->authors = $allow;

        return $this;
    }

    public function allowsAuthors(): bool
    {
        return $this->authors;
    }

    /** Only one page with this template on the site. */
    public function single(bool $single = true): self
    {
        $this->single = $single;

        return $this;
    }

    /** The home page: address «/», top level, never moves, never unpublished. */
    public function root(): self
    {
        $this->root = true;
        $this->parents = null;
        $this->single = true;

        return $this;
    }

    /** Only at the top level. */
    public function topLevel(): self
    {
        $this->parents = null;

        return $this;
    }

    /** Only under pages with one of these templates. */
    public function parents(string ...$templates): self
    {
        $this->parents = [] === $templates ? 'any' : \array_values($templates);

        return $this;
    }

    /** At the top level or under any page (the default). */
    public function anyParent(): self
    {
        $this->parents = 'any';

        return $this;
    }

    public function fields(FieldInterface ...$fields): self
    {
        $this->fields = \array_values($fields);

        return $this;
    }

    /** 'all' = sections can be added, false = the page has no sections. */
    public function sections(string|false $sections = 'all'): self
    {
        $this->sections = $sections;

        return $this;
    }

    /**
     * The list of pages the page fills by itself (of: template keys) or the list of a collection (collection: key).
     *
     * @param list<string> $of
     */
    public function listing(array $of = [], string $label = '', ?string $onlyIf = null, ?string $collection = null): self
    {
        $this->listing = null !== $collection
            ? ['collection' => $collection, 'label' => $label]
            : \array_filter(['of' => $of, 'label' => $label, 'onlyIf' => $onlyIf], static fn (mixed $v): bool => null !== $v);
        unset($this->keys['listing']);

        return $this;
    }

    /** The H1 of the page is this template field (then no section carries the H1). */
    public function h1(string $field): self
    {
        $this->h1Field = $field;

        return $this;
    }

    /** Nothing can be put under a page with this template. */
    public function leaf(bool $leaf = true): self
    {
        $this->leaf = $leaf;

        return $this;
    }

    /** Label of the quick link in the rail. */
    public function shortcut(string $label): self
    {
        $this->shortcut = $label;
        unset($this->keys['shortcut']);

        return $this;
    }

    /** Keys of the template fields a list card shows. */
    public function card(?string $image = null, ?string $title = null, ?string $text = null, ?string $date = null): self
    {
        $this->card = \array_filter(['image' => $image, 'title' => $title, 'text' => $text, 'date' => $date], static fn (?string $v): bool => null !== $v);

        return $this;
    }

    /**
     * Menu areas the layout of this template shows (default: the site layout).
     *
     * @param list<string> $top
     * @param list<string> $bottom
     */
    public function areas(array $top = [], array $bottom = []): self
    {
        $this->areas = ['top' => $top, 'bottom' => $bottom];

        return $this;
    }

    /** The class that described the template (hooks are called on it). */
    public function bind(?TemplateResource $resource): self
    {
        $this->resource = $resource;

        return $this;
    }

    public function resource(): ?TemplateResource
    {
        return $this->resource;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function title(): string
    {
        return (string) $this->text('name', $this->name);
    }

    public function isCore(): bool
    {
        return $this->core;
    }

    public function isSingle(): bool
    {
        return $this->single;
    }

    public function isRoot(): bool
    {
        return $this->root;
    }

    public function isLeaf(): bool
    {
        return $this->leaf;
    }

    /** @return 'any'|list<string>|null */
    public function parentRule(): string|array|null
    {
        return $this->parents;
    }

    public function hasSections(): bool
    {
        return false !== $this->sections;
    }

    public function h1Field(): ?string
    {
        return $this->h1Field;
    }

    /** @return array<string, mixed>|null */
    public function listingRule(): ?array
    {
        if (null === $this->listing || !isset($this->keys['listing'])) {
            return $this->listing;
        }

        return ['label' => $this->text('listing', null)] + $this->listing;
    }

    /** @return array<string, string>|null */
    public function cardRule(): ?array
    {
        return $this->card;
    }

    public function shortcutLabel(): ?string
    {
        return $this->text('shortcut', $this->shortcut);
    }

    /** @return list<FieldInterface> editable fields */
    public function fieldList(): array
    {
        return \array_values(\array_filter($this->fields, static fn (FieldInterface $f): bool => !$f->isNote()));
    }

    public function field(string $key): ?FieldInterface
    {
        foreach ($this->fields as $f) {
            if ($f->key() === $key) {
                return $f;
            }
        }

        return null;
    }

    /** @return 'layout'|array{top: list<string>, bottom: list<string>} */
    public function areasRule(): string|array
    {
        return $this->areas;
    }

    /**
     * @return array<string, mixed> the template as the admin reads it; with a viewer: only the fields he may see
     */
    public function toArray(?Viewer $viewer = null): array
    {
        $out = [
            'key' => $this->key,
            'name' => $this->title(),
            'about' => $this->text('about', $this->about),
            'core' => $this->core,
            'parents' => $this->parents,
            'fields' => FormFields::toArray($this->fields, $viewer),
            'sections' => $this->sections,
            'areas' => $this->areas,
        ];
        if ($this->single) {
            $out['single'] = true;
        }
        if ($this->root) {
            $out['root'] = true;
        }
        if (null !== $this->listing) {
            $out['listing'] = $this->listingRule();
        }
        if (null !== $this->h1Field) {
            $out['h1'] = ['field' => $this->h1Field];
        }
        if ($this->leaf) {
            $out['leaf'] = true;
        }
        if (null !== ($shortcut = $this->shortcutLabel())) {
            $out['shortcut'] = $shortcut;
        }
        if (null !== $this->card) {
            $out['card'] = $this->card;
        }

        return $out;
    }
}
