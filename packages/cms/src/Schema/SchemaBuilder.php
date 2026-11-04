<?php

declare(strict_types=1);

namespace Itk\Cms\Schema;

use Itk\Cms\Field\FieldInterface;
use Itk\Cms\I18n\I18n;

/**
 * The builder a site's config/itk/schema.php receives:
 *
 *   return static function (SchemaBuilder $s): void {
 *       $s->design(headlineMarks: ['gradient']);
 *       $s->menuArea('header', 'Меню в шапке', depth: 2);
 *       $s->section('hero', 'Первый экран')->group('Первый экран')->fields(Field::headline('headline', 'Заголовок')->max(48)->required());
 *       $s->template('catalog', 'Каталог')->single()->topLevel()->listing(of: ['product'], label: 'Товары');
 *       $s->collection('ambassadors', 'Амбассадоры')->view('cards')->sortable()->fields(...);
 *       $s->settings('contacts', 'Контакты')->fields(Field::text('phone', 'Телефон'));
 *       $s->form('call', 'Заявка на звонок')->fields(Field::text('phone', 'Телефон')->required());
 *   };
 *
 * The core templates (home, default, blog, blog_category, blog_article) are registered first; a site changes one by calling $s->template('blog_article') again.
 * A site without a blog calls $s->withoutBlog() (or sets itk_cms.blog: false): the three blog templates go and so does the «Статьи блога» link of the rail.
 */
final class SchemaBuilder
{
    /** @var list<string> */
    private array $headlineMarks = [];
    /** @var array<string, bool> */
    private array $features = ['ai' => false];
    /** @var array<string, MenuAreaDef> */
    private array $menuAreas = [];
    /** @var array{top: list<string>, bottom: list<string>}|null */
    private ?array $layout = null;
    /** @var array<string, SectionType> */
    private array $sections = [];
    /** @var array<string, Template> */
    private array $templates = [];
    /** @var array<string, Collection> */
    private array $collections = [];
    /** @var array<string, SettingsGroup> */
    private array $settings = [];
    /** @var array<string, FormDef> */
    private array $forms = [];
    /** Who described what: "kind:key" => 'core' | file | class. Two descriptions of one key are an error (a core template may be changed). */
    private array $origins = [];
    private string $source = 'core';
    /** @var list<string> what validate() of the built schema reports besides the definitions themselves */
    private array $problems = [];

    /** @param bool $blog false = a site without a blog: the templates blog, blog_category and blog_article are not registered (itk_cms.blog: false) */
    public function __construct(private readonly string $mainLocale = 'ru', bool $blog = true)
    {
        CoreSchema::register($this, $blog);
        $this->source = 'config/itk/schema.php';
    }

    /** The site has no blog: the core templates blog, blog_category and blog_article are taken out (a template of the site with such a key, described after this call, stays). */
    public function withoutBlog(): self
    {
        foreach (CoreSchema::BLOG as $key) {
            if (($this->templates[$key] ?? null)?->isCore()) {
                unset($this->templates[$key], $this->origins['template:' . $key]);
            }
        }

        return $this;
    }

    /** Names the place the following definitions come from (used in the error about a key described twice). */
    public function from(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    /**
     * A resource class takes the key: error when another file or class has it already. Core templates can be changed by a class.
     *
     * @param 'section'|'template'|'collection'|'settings' $kind
     */
    public function claim(string $kind, string $key, string $source): void
    {
        $id = $kind . ':' . $key;
        $had = $this->origins[$id] ?? null;
        if (null !== $had && 'core' !== $had) {
            throw new \LogicException(\sprintf('The %s "%s" is described twice: in %s and in %s. Keep one description.', $kind, $key, $had, $source));
        }
        $this->origins[$id] = $source;
    }

    /**
     * What the site's design can show. headlineMarks: 'gradient' = words in gradient colour (<span class="grad"> in a headline).
     *
     * @param list<string> $headlineMarks
     */
    public function design(array $headlineMarks = []): self
    {
        $this->headlineMarks = \array_values($headlineMarks);

        return $this;
    }

    public function feature(string $name, bool $on = true): self
    {
        $this->features[$name] = $on;

        return $this;
    }

    public function menuArea(string $key, string $name, int $depth = 1): self
    {
        if (isset($this->menuAreas[$key])) {
            $this->problems[] = I18n::t('model.problem_menu_twice', ['key' => $key]);
        }
        $this->menuAreas[$key] = new MenuAreaDef($key, $name, \max(1, \min(2, $depth)));

        return $this;
    }

    /**
     * The menu areas the site layout shows on every template (default: «header» on top, the rest at the bottom).
     *
     * @param list<string> $top
     * @param list<string> $bottom
     */
    public function layout(array $top = [], array $bottom = []): self
    {
        $this->layout = ['top' => $top, 'bottom' => $bottom];

        return $this;
    }

    public function section(string $key, ?string $name = null): SectionType
    {
        $this->origins['section:' . $key] ??= $this->source;

        return $this->sections[$key] ??= new SectionType($key, $name ?? $key);
    }

    public function template(string $key, ?string $name = null): Template
    {
        $this->origins['template:' . $key] ??= $this->source;
        $t = $this->templates[$key] ??= new Template($key, $name ?? $key);
        if (null !== $name) {
            $t->name($name);
        }

        return $t;
    }

    public function collection(string $key, ?string $title = null): Collection
    {
        $this->origins['collection:' . $key] ??= $this->source;

        return $this->collections[$key] ??= new Collection($key, $title ?? $key);
    }

    public function settings(string $key, ?string $title = null): SettingsGroup
    {
        $this->origins['settings:' . $key] ??= $this->source;
        $g = $this->settings[$key] ??= new SettingsGroup($key, $title ?? $key);
        if (null !== $title) {
            $g->title($title);
        }

        return $g;
    }

    public function form(string $key, ?string $title = null): FormDef
    {
        return $this->forms[$key] ??= new FormDef($key, $title ?? $key);
    }

    public function build(): Schema
    {
        foreach ($this->collections as $collection) {
            $collection->checkViews();
        }
        $areas = $this->menuAreas;
        $layout = $this->layout;
        if (null === $layout) {
            $top = isset($areas['header']) ? ['header'] : [];
            $bottom = \array_values(\array_diff(\array_keys($areas), $top));
            $layout = ['top' => $top, 'bottom' => $bottom];
        }

        // the built-in «Сайт» group (name and domain) always exists; fields the site adds to it stay
        $settings = $this->settings;
        if (!isset($settings['site'])) {
            $settings['site'] = (new SettingsGroup('site', 'site'))->titleKey('model.site_group');
        }
        $site = $settings['site'];
        $existing = \array_map(static fn (FieldInterface $f): string => $f->key(), $site->fieldList());
        $core = [];
        if (!\in_array('siteName', $existing, true)) {
            $core[] = Field::text('siteName', 'siteName')->texts(label: 'model.site_name')->max(40)->required();
        }
        if (!\in_array('domain', $existing, true)) {
            $core[] = Field::text('domain', 'domain')->texts(label: 'model.site_domain', help: 'model.site_domain_help')->max(60)->required();
        }
        if ([] !== $core) {
            $site->prepend(...$core);
        }

        return new Schema(
            mainLocale: $this->mainLocale,
            headlineMarks: $this->headlineMarks,
            features: $this->features,
            menuAreas: $areas,
            layout: $layout,
            sections: $this->sections,
            templates: $this->templates,
            collections: $this->collections,
            settings: $settings,
            forms: $this->forms,
            buildProblems: $this->problems,
        );
    }
}
