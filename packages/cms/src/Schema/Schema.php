<?php

declare(strict_types=1);

namespace Itk\Cms\Schema;

use Itk\Cms\Field\FieldInterface;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Security\Viewer;

/**
 * The loaded site schema (read model) and its export to the admin JSON.
 * Built once per request by SchemaLoader from the site's config/itk/schema.php.
 */
final class Schema
{
    public const ACTIONS = ['view', 'edit', 'publish', 'delete'];

    /** Keys of Settings that are not in any group (Notifications tab): Telegram on/off and chats per form, e-mail addresses per form, accept requests. */
    public const CORE_SETTING_KEYS = ['tgOn', 'tgRoutes', 'mailRoutes', 'forms'];

    /** The texts of robots.txt and llms.txt ('' = the site's own text is not set): core keys, shared by all languages, written through the SEO screen (area `set:seo`) and the history. */
    public const CRAWLER_SETTING_KEYS = ['robotsTxt', 'llmsTxt'];

    /**
     * @param list<string> $headlineMarks
     * @param array<string, bool> $features
     * @param array<string, MenuAreaDef> $menuAreas
     * @param array{top: list<string>, bottom: list<string>} $layout
     * @param array<string, SectionType> $sections
     * @param array<string, Template> $templates
     * @param array<string, Collection> $collections
     * @param array<string, SettingsGroup> $settings
     * @param array<string, FormDef> $forms
     * @param list<string> $buildProblems what the builder saw while the definitions were made (a menu area described twice): validate() reports them
     */
    public function __construct(
        private readonly string $mainLocale,
        private readonly array $headlineMarks,
        private readonly array $features,
        private readonly array $menuAreas,
        private readonly array $layout,
        private readonly array $sections,
        private readonly array $templates,
        private readonly array $collections,
        private readonly array $settings,
        private readonly array $forms,
        private readonly array $buildProblems = [],
    ) {
    }

    public function mainLocale(): string
    {
        return $this->mainLocale;
    }

    /** @return list<string> */
    public function headlineMarks(): array
    {
        return $this->headlineMarks;
    }

    public function allowsGradient(): bool
    {
        return \in_array('gradient', $this->headlineMarks, true);
    }

    /** @return array<string, SectionType> */
    public function sections(): array
    {
        return $this->sections;
    }

    public function section(string $key): ?SectionType
    {
        return $this->sections[$key] ?? null;
    }

    /** @return array<string, Template> */
    public function templates(): array
    {
        return $this->templates;
    }

    public function template(string $key): ?Template
    {
        return $this->templates[$key] ?? null;
    }

    /** @return array<string, Collection> */
    public function collections(): array
    {
        return $this->collections;
    }

    public function collection(string $key): ?Collection
    {
        return $this->collections[$key] ?? null;
    }

    /** @return array<string, MenuAreaDef> */
    public function menuAreas(): array
    {
        return $this->menuAreas;
    }

    /** @return array{top: list<string>, bottom: list<string>} */
    public function layout(): array
    {
        return $this->layout;
    }

    /** @return array<string, SettingsGroup> */
    public function settingsGroups(): array
    {
        return $this->settings;
    }

    /** @return list<FieldInterface> every field of every settings group */
    public function settingsFields(): array
    {
        $out = [];
        foreach ($this->settings as $g) {
            foreach ($g->fieldList() as $f) {
                $out[] = $f;
            }
        }

        return $out;
    }

    public function settingsField(string $key): ?FieldInterface
    {
        foreach ($this->settingsFields() as $f) {
            if ($f->key() === $key) {
                return $f;
            }
        }

        return null;
    }

    /** @return array<string, FormDef> */
    public function forms(): array
    {
        return $this->forms;
    }

    public function form(string $key): ?FormDef
    {
        return $this->forms[$key] ?? null;
    }

    /**
     * What is wrong with the schema, one sentence for each problem with the name of the section it is in; an empty list for a good schema.
     * Called when the schema is loaded in debug and by itk:schema:lint.
     *
     * @return list<string>
     */
    public function validate(): array
    {
        $out = [];
        foreach ($this->sections as $key => $s) {
            $at = I18n::t('model.problem_at_section', ['key' => $key]);
            $out = [...$out, ...$this->keyProblems($at, (string) $key), ...$this->fieldProblems($at, $s->allFields())];
        }
        foreach ($this->templates as $key => $t) {
            $at = I18n::t('model.problem_at_template', ['key' => $key]);
            $out = [...$out, ...$this->keyProblems($at, (string) $key), ...$this->fieldProblems($at, $t->fieldList())];
            $parents = $t->parentRule();
            if (\is_array($parents)) {
                $out = [...$out, ...$this->unknownTemplates($at, 'parents()', $parents)];
            }
            $out = [...$out, ...$this->unknownTemplates($at, 'listing(of:)', (array) ($t->listingRule()['of'] ?? []))];
        }
        foreach ($this->collections as $key => $c) {
            $at = I18n::t('model.problem_at_collection', ['key' => $key]);
            $fields = [];
            foreach ($c->tabList() as $tab) {
                foreach ($tab['fields'] as $f) {
                    $fields[] = $f;
                }
            }
            $out = [...$out, ...$this->keyProblems($at, (string) $key), ...$this->fieldProblems($at, $fields)];
            foreach ($fields as $f) {
                if ('version' === $f->key()) {
                    // the record of a collection carries the version of its draft under this key (flat(), `baseVersion` of the autosave): a field of the same name would be covered by it
                    $out[] = I18n::t('model.problem_version_taken', ['at' => $at]);
                }
            }
            $title = $c->declaredTitleField();
            if (null !== $title && null === $c->field($title)) {
                $out[] = I18n::t('model.problem_title_field', ['at' => $at, 'title' => $title, 'keys' => $this->listKeys(\array_map(static fn (FieldInterface $f): string => $f->key(), $c->fieldList()))]);
            }
        }
        $seen = [];
        foreach ($this->settings as $key => $g) {
            $at = I18n::t('model.problem_at_settings', ['key' => $key]);
            $out = [...$out, ...$this->keyProblems($at, (string) $key), ...$this->fieldProblems($at, $g->fieldList())];
            foreach ($g->fieldList() as $f) {
                if (isset($seen[$f->key()])) {
                    $out[] = I18n::t('model.problem_setting_key_taken', ['at' => $at, 'key' => $f->key(), 'group' => $seen[$f->key()]]);
                } else {
                    $seen[$f->key()] = (string) $key;
                }
            }
        }
        foreach ($this->forms as $key => $f) {
            $at = I18n::t('model.problem_at_form', ['key' => $key]);
            $out = [...$out, ...$this->keyProblems($at, (string) $key), ...$this->fieldProblems($at, $f->fieldList())];
            if ([] === $f->fieldList()) {
                $out[] = I18n::t('model.problem_form_empty', ['at' => $at]);
            }
        }
        foreach ($this->menuAreas as $key => $_) {
            $out = [...$out, ...$this->keyProblems(I18n::t('model.problem_at_menu', ['key' => $key]), (string) $key)];
        }

        return [...$out, ...$this->buildProblems];
    }

    /** @return list<string> */
    private function keyProblems(string $at, string $key): array
    {
        return Key::isValid($key) ? [] : [I18n::t('model.problem_key_invalid', ['at' => $at, 'key' => $key, 'rule' => Key::rule()])];
    }

    /**
     * Keys of the fields (and of the fields inside repeaters): the format, and each key once.
     *
     * @param list<FieldInterface> $fields
     *
     * @return list<string>
     */
    private function fieldProblems(string $at, array $fields): array
    {
        $out = [];
        $seen = [];
        foreach ($fields as $f) {
            $key = $f->key();
            if (!Key::isValid($key)) {
                $out[] = I18n::t('model.problem_field_key_invalid', ['at' => $at, 'key' => $key, 'rule' => Key::rule()]);
            }
            if (isset($seen[$key])) {
                $out[] = I18n::t('model.problem_field_key_repeated', ['at' => $at, 'key' => $key]);
            }
            $seen[$key] = true;
            if ([] !== $f->subFields()) {
                $out = [...$out, ...$this->fieldProblems(I18n::t('model.problem_at_field', ['at' => $at, 'key' => $key]), $f->subFields())];
            }
        }

        return $out;
    }

    /**
     * @param list<string> $named
     *
     * @return list<string>
     */
    private function unknownTemplates(string $at, string $method, array $named): array
    {
        $out = [];
        foreach ($named as $key) {
            if (!isset($this->templates[(string) $key])) {
                $out[] = I18n::t('model.problem_unknown_template', ['at' => $at, 'method' => $method, 'key' => $key, 'keys' => $this->listKeys(\array_keys($this->templates))]);
            }
        }

        return $out;
    }

    /** @param list<int|string> $keys */
    private function listKeys(array $keys): string
    {
        return [] === $keys ? I18n::t('model.problem_nothing') : \implode(', ', \array_map(static fn (int|string $k): string => I18n::t('model.problem_quoted', ['value' => $k]), $keys));
    }

    /** @return list<FieldInterface> */
    public function sectionFields(string $type): array
    {
        return ($this->sections[$type] ?? null)?->fieldList() ?? [];
    }

    /** @return list<FieldInterface> */
    public function templateFields(string $template): array
    {
        return ($this->templates[$template] ?? null)?->fieldList() ?? [];
    }

    /** Page-level fields outside sections (title, address, SEO); keys may be dotted. */
    public static function pageFields(): array
    {
        return [
            Field::text('title', I18n::t('model.page_title'))->max(70)->required()->help(I18n::t('model.page_title_help')),
            Field::text('url', I18n::t('model.page_url'))->max(80)->required()->help(I18n::t('model.page_url_help')),
            Field::text('seo.title', I18n::t('model.page_seo_title'))->max(70),
            Field::textarea('seo.description', I18n::t('model.page_seo_description'))->max(160)->rows(3),
        ];
    }

    /** Templates whose cards show a date: the ones an «Автор» writes. @return list<string> */
    public function articleTemplates(): array
    {
        $out = [];
        foreach ($this->templates as $t) {
            if (isset(($t->cardRule() ?? [])['date'])) {
                $out[] = $t->key();
            }
        }

        return $out;
    }

    /**
     * Permission areas generated from the structure (mirror of admin/src/utils/permissions.js permissionAreas()).
     *
     * @return list<array<string, mixed>>
     */
    public function permissionAreas(): array
    {
        $all = self::ACTIONS;
        $areas = [[
            'key' => 'pages', 'group' => 'content', 'label' => I18n::t('model.area_pages'), 'actions' => $all, 'scope' => 'templates',
            'hint' => I18n::t('model.area_pages_hint'),
        ]];
        foreach ($this->collections as $c) {
            $areas[] = ['key' => 'col:' . $c->key(), 'group' => 'content', 'label' => $c->title(), 'actions' => null !== $c->singletonId() ? ['view', 'edit', 'publish'] : $all];
        }
        $areas[] = ['key' => 'requests', 'group' => 'data', 'label' => I18n::t('model.area_requests'), 'actions' => ['view', 'edit'], 'hint' => I18n::t('model.area_requests_hint')];
        $areas[] = ['key' => 'media', 'group' => 'data', 'label' => I18n::t('model.area_media'), 'actions' => ['view', 'edit', 'delete'], 'hint' => I18n::t('model.area_media_hint')];
        foreach ($this->settings as $g) {
            $areas[] = ['key' => 'set:' . $g->key(), 'group' => 'settings', 'label' => $g->groupTitle(), 'actions' => ['view', 'edit'], 'hint' => ''];
        }
        $areas[] = ['key' => 'set:menus', 'group' => 'settings', 'label' => I18n::t('model.area_menus'), 'actions' => ['view', 'edit'], 'hint' => ''];
        $areas[] = ['key' => 'set:redirects', 'group' => 'settings', 'label' => I18n::t('model.area_redirects'), 'actions' => ['view', 'edit', 'delete'], 'hint' => I18n::t('model.area_redirects_hint')];
        $areas[] = ['key' => 'set:seo', 'group' => 'settings', 'label' => I18n::t('model.area_seo'), 'actions' => ['view', 'edit', 'delete'], 'hint' => I18n::t('model.area_seo_hint')];
        $areas[] = ['key' => 'set:notifications', 'group' => 'settings', 'label' => I18n::t('model.area_notifications'), 'actions' => ['view', 'edit'], 'hint' => ''];
        $areas[] = ['key' => 'set:mail', 'group' => 'settings', 'label' => I18n::t('model.area_mail'), 'actions' => ['view', 'edit'], 'hint' => I18n::t('model.area_mail_hint')];
        $areas[] = ['key' => 'set:theme', 'group' => 'settings', 'label' => I18n::t('model.area_theme'), 'actions' => ['view', 'edit'], 'hint' => I18n::t('model.area_theme_hint')];
        $areas[] = ['key' => 'set:languages', 'group' => 'settings', 'label' => I18n::t('model.area_languages'), 'actions' => ['view', 'edit', 'delete'], 'hint' => I18n::t('model.area_languages_hint')];
        $areas[] = ['key' => 'set:users', 'group' => 'settings', 'label' => I18n::t('model.area_users'), 'actions' => ['view', 'edit', 'delete'], 'hint' => I18n::t('model.area_users_hint')];
        $areas[] = ['key' => 'set:mcp', 'group' => 'settings', 'label' => I18n::t('model.area_mcp'), 'actions' => ['view', 'edit'], 'hint' => I18n::t('model.area_mcp_hint')];
        $areas[] = ['key' => 'templates', 'group' => 'settings', 'label' => I18n::t('model.area_templates'), 'actions' => ['view'], 'hint' => I18n::t('model.area_templates_hint')];
        $areas[] = ['key' => 'dev', 'group' => 'dev', 'label' => I18n::t('model.area_dev'), 'actions' => ['view', 'edit'], 'hint' => I18n::t('model.area_dev_hint')];
        foreach ($this->collections as $c) {
            foreach ($c->customActions() as $a) {
                if ($a->hasPermission()) {
                    $areas[] = ['key' => 'action:' . $c->key() . ':' . $a->key(), 'group' => 'actions', 'label' => $c->title() . ': ' . $a->label(), 'actions' => ['view'], 'hint' => I18n::t('model.area_action_hint')];
                }
            }
        }

        return $areas;
    }

    /**
     * Section groups in the order of the block library: «Первый экран» first, then the rest in the order they appear.
     *
     * @return list<string>
     */
    public function sectionGroups(): array
    {
        $groups = [SectionType::FIRST_SCREEN];
        foreach ($this->sections as $s) {
            if (!\in_array($s->groupName(), $groups, true)) {
                $groups[] = $s->groupName();
            }
        }

        return $groups;
    }

    /**
     * The schema as the admin reads it (GET /admin/api/schema). Same shapes as admin/src/schema/*.js.
     * With a viewer the fields, actions and collections he may not use are left out (a collection he has no `col:<key>` view right for is not listed at all).
     *
     * @return array<string, mixed>
     */
    public function toAdminArray(?Viewer $viewer = null): array
    {
        $collections = null === $viewer ? $this->collections : \array_filter($this->collections, static fn (Collection $c): bool => $c->isVisibleTo($viewer) && $viewer->can('col:' . $c->key(), 'view'));

        return [
            'mainLocale' => $this->mainLocale,
            'design' => ['headlineMarks' => $this->headlineMarks],
            'features' => $this->features,
            'sections' => \array_values(\array_map(static fn (SectionType $s): array => $s->toArray($viewer), $this->sections)),
            'sectionGroups' => $this->sectionGroups(),
            'pageFields' => \array_map(static fn (FieldInterface $f): array => $f->toArray(), self::pageFields()),
            'templates' => \array_values(\array_map(static fn (Template $t): array => $t->toArray($viewer), $this->templates)),
            'layout' => $this->layout,
            'collections' => \array_values(\array_map(static fn (Collection $c): array => $c->toArray($viewer), $collections)),
            'menuAreas' => \array_values(\array_map(static fn (MenuAreaDef $a): array => $a->toArray(), $this->menuAreas)),
            'settings' => ['groups' => \array_values(\array_map(static fn (SettingsGroup $g): array => $g->toArray($viewer), $this->settings))],
            'forms' => \array_values(\array_map(static fn (FormDef $f): array => $f->toArray(), $this->forms)),
            'permissionAreas' => $this->permissionAreas(),
        ];
    }
}
