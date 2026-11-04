<?php

declare(strict_types=1);

namespace Itk\Cms\Field;

use Itk\Cms\I18n\I18n;
use Itk\Cms\Security\Viewer;

/**
 * The fluent API every field has. Text::make('Заголовок', 'title')->required()->max(70)->sortable()->filterable().
 *
 * One field works in the form, as a list column, as a filter and in the detail view; toArray() is the shape of the admin
 * (admin/src/utils/fields.js): { k, t, label, max?, req?, help?, rows?, rich?, article?, opts?, sub?, … }
 * + { list: { show, sortable, width }, filter: { kind, options }|null, detail }.
 */
abstract class AbstractField implements FieldInterface
{
    /**
     * The field as the admin receives it (short keys of the admin shape: k, t, label, req, help, …); toArray() starts from it.
     *
     * @var array<string, mixed>
     */
    protected array $definition;

    private ?string $pattern = null;
    private ?string $patternMessage = null;
    private bool $sortable = false;
    private bool $searchable = false;
    private bool $filterable = false;
    private ?FilterKind $filterKind = null;
    private ?bool $column = null;
    private bool $hideOnForm = false;
    private bool $hideOnDetail = false;
    private ?int $width = null;
    private bool $locked = false;
    private string|\Closure|null $canSee = null;
    /** @var array{label?: string, help?: string} catalogue keys of the texts of the bundle's own fields, read in the language of the person who asks */
    private array $keys = [];

    /**
     * @param string $label what the editor sees
     * @param string $key where the value is stored; required for every field that holds data (only a Note may go without)
     */
    final public function __construct(string $label, string $key = '')
    {
        if ('' === $key && !$this instanceof Note) {
            throw new \InvalidArgumentException(\sprintf('The field «%s» needs a key: %s::make(\'%s\', \'key\').', $label, (new \ReflectionClass($this))->getShortName(), $label));
        }
        $this->definition = ['k' => '' !== $key ? $key : 'note_' . \substr(\md5($label), 0, 8), 't' => $this->wireType(), 'label' => $label];
        $this->configure();
    }

    /** Label first, key second (like MoonShine). */
    public static function make(string $label, string $key = ''): static
    {
        return new static($label, $key);
    }

    /** The `t` of the admin shape. */
    abstract protected function wireType(): string;

    /** Sets the flags of a kind (rich, inputType …); runs once from the constructor. */
    protected function configure(): void
    {
    }

    /** The filter a field gets from filterable() without an argument. */
    protected function defaultFilterKind(): FilterKind
    {
        return FilterKind::Text;
    }

    /* ---------- the fluent API ---------- */

    public function max(int $max): static
    {
        $this->definition['max'] = $max;

        return $this;
    }

    public function required(bool $required = true): static
    {
        if ($required) {
            $this->definition['req'] = true;
        } else {
            unset($this->definition['req']);
        }

        return $this;
    }

    /** A line of help under the field. */
    public function hint(string $hint): static
    {
        $this->definition['help'] = $hint;
        unset($this->keys['help']);

        return $this;
    }

    /**
     * The label and the help of a field the bundle itself defines, by catalogue key (the schema is built before any request has chosen a language,
     * so the text is looked up when it is read). A hint() called later wins. The label given to make() stays as the fallback.
     */
    public function texts(?string $label = null, ?string $help = null): static
    {
        if (null !== $label) {
            $this->keys['label'] = $label;
        }
        if (null !== $help) {
            $this->keys['help'] = $help;
        }

        return $this;
    }

    /** Same as hint() (the name of the first iteration). */
    public function help(string $help): static
    {
        return $this->hint($help);
    }

    /** Value for a new record; the admin otherwise uses the type's default. */
    public function default(mixed $value): static
    {
        $this->definition['default'] = $value;

        return $this;
    }

    /** Same as default(). */
    public function initial(mixed $value): static
    {
        return $this->default($value);
    }

    /** Settings only: the value exists once per content language. */
    public function translatable(): static
    {
        $this->definition['tr'] = true;

        return $this;
    }

    /** The editor sees the value but cannot change it (code and hooks still can). */
    public function readonly(bool $readonly = true): static
    {
        $this->locked = $readonly;

        return $this;
    }

    /** The list column of this field can be sorted. */
    public function sortable(bool $sortable = true): static
    {
        $this->sortable = $sortable;

        return $this;
    }

    /** The search box of the list looks in this field. */
    public function searchable(bool $searchable = true): static
    {
        $this->searchable = $searchable;

        return $this;
    }

    /** The field becomes a filter of the list; without an argument the kind follows the field (select → select, date → dateRange …). */
    public function filterable(?FilterKind $kind = null): static
    {
        $this->filterable = true;
        $this->filterKind = $kind;

        return $this;
    }

    /** Show as a column of the list. */
    public function column(): static
    {
        $this->column = true;

        return $this;
    }

    public function showOnIndex(): static
    {
        return $this->column();
    }

    public function hideOnIndex(): static
    {
        $this->column = false;

        return $this;
    }

    public function hideOnForm(): static
    {
        $this->hideOnForm = true;

        return $this;
    }

    public function hideOnDetail(): static
    {
        $this->hideOnDetail = true;

        return $this;
    }

    /** Width of the list column, in pixels. */
    public function width(int $width): static
    {
        $this->width = $width;

        return $this;
    }

    /**
     * Who sees the field: a permission area ('set:users', 'col:tours'), a right 'area:action' ('col:tours:publish', 'pages:edit'),
     * or a closure fn (Itk\Cms\Security\Viewer $v): bool. The server leaves the field out of the schema and ignores its value for everybody else.
     */
    public function canSee(string|\Closure $who): static
    {
        $this->canSee = $who;

        return $this;
    }

    public function rows(int $rows): static
    {
        $this->definition['rows'] = $rows;

        return $this;
    }

    /**
     * A textarea whose value is output as raw HTML (scripts included). Only a person who manages users may add or change it:
     * everybody else keeps the existing value (the guard is Itk\Cms\Content\RawFieldGuard).
     */
    public function raw(): static
    {
        $this->definition['raw'] = true;

        return $this;
    }

    /** The select that is the «Вид секции» option. */
    public function variant(): static
    {
        $this->definition['variant'] = true;

        return $this;
    }

    /** The headline editor offers a forced line break. */
    public function lineBreak(): static
    {
        $this->definition['brk'] = true;

        return $this;
    }

    public function addLabel(string $label): static
    {
        $this->definition['addLabel'] = $label;

        return $this;
    }

    /** Singular noun of a repeater item («слайд»). */
    public function noun(string $noun): static
    {
        $this->definition['noun'] = $noun;

        return $this;
    }

    /**
     * The field takes half of the row: two neighbours with half() stand side by side (years «from» and «to»), a column on a phone.
     * A single half() field, or the third in a row, stays full width. A field with section() always starts a row.
     */
    public function half(bool $half = true): static
    {
        if ($half) {
            $this->definition['half'] = true;
        } else {
            unset($this->definition['half']);
        }

        return $this;
    }

    /**
     * A heading of a part of the form, drawn before this field («Фото и текст»); the part lasts until the next section().
     * Works in the form of a page, section, record, settings and in the items of a Repeater. A line of explanation under the heading: a Note after it.
     */
    public function section(string $title): static
    {
        if ('' === $title) {
            unset($this->definition['section']);
        } else {
            $this->definition['section'] = $title;
        }

        return $this;
    }

    public function inputType(string $type): static
    {
        $this->definition['inputType'] = $type;

        return $this;
    }

    /** Note only: link under the text. */
    public function linkTo(string $to, string $text): static
    {
        $this->definition['link'] = $to;
        $this->definition['linkText'] = $text;

        return $this;
    }

    /** Server-side check of a site form value (preg pattern with delimiters). */
    public function pattern(string $regex, ?string $message = null): static
    {
        $this->pattern = $regex;
        $this->patternMessage = $message;

        return $this;
    }

    /* ---------- what the core reads ---------- */

    public function key(): string
    {
        return $this->definition['k'];
    }

    public function type(): string
    {
        return $this->definition['t'];
    }

    public function label(): string
    {
        return isset($this->keys['label']) ? I18n::t($this->keys['label']) : $this->definition['label'];
    }

    public function isRequired(): bool
    {
        return !empty($this->definition['req']);
    }

    public function isNote(): bool
    {
        return 'readonly' === $this->definition['t'];
    }

    public function isRaw(): bool
    {
        return !empty($this->definition['raw']);
    }

    public function isHeadline(): bool
    {
        return 'headline' === $this->definition['t'];
    }

    public function isRich(): bool
    {
        return 'headline' === $this->definition['t'] || ('textarea' === $this->definition['t'] && !empty($this->definition['rich']));
    }

    public function isTranslatable(): bool
    {
        return !empty($this->definition['tr']);
    }

    public function maxLength(): ?int
    {
        return $this->definition['max'] ?? null;
    }

    public function regex(): ?string
    {
        return $this->pattern;
    }

    public function regexMessage(): string
    {
        return $this->patternMessage ?? I18n::t('model.field_pattern_invalid');
    }

    public function richMode(): ?string
    {
        if (!$this->isRich()) {
            return null;
        }

        return 'headline' === $this->definition['t'] ? 'headline' : (!empty($this->definition['article']) ? 'article' : 'rich');
    }

    /** @return list<FieldInterface> */
    public function subFields(): array
    {
        return $this->definition['sub'] ?? [];
    }

    public function subField(string $key): ?FieldInterface
    {
        foreach ($this->subFields() as $f) {
            if ($f->key() === $key) {
                return $f;
            }
        }

        return null;
    }

    public function hasDefault(): bool
    {
        return \array_key_exists('default', $this->definition);
    }

    public function defaultValue(): mixed
    {
        return $this->definition['default'] ?? null;
    }

    /** @return list<array{0: string, 1: string}> */
    public function choices(): array
    {
        return $this->definition['opts'] ?? [];
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function visibleTo(Viewer $viewer): bool
    {
        if (null === $this->canSee) {
            return true;
        }

        return \is_string($this->canSee) ? $viewer->allows($this->canSee) : (bool) ($this->canSee)($viewer);
    }

    public function isSortable(): bool
    {
        return $this->sortable;
    }

    public function isSearchable(): bool
    {
        return $this->searchable;
    }

    public function filterKind(): ?FilterKind
    {
        return $this->filterable ? ($this->filterKind ?? $this->defaultFilterKind()) : null;
    }

    public function listColumn(): ?bool
    {
        return $this->column;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = $this->definition;
        unset($out['default']);
        $out['label'] = $this->label();
        if (isset($this->keys['help'])) {
            $out['help'] = I18n::t($this->keys['help']);
        }
        if (isset($out['sub'])) {
            $out['sub'] = \array_map(static fn (FieldInterface $f): array => $f->toArray(), $out['sub']);
        }
        $kind = $this->filterKind();
        $out['list'] = ['show' => $this->column, 'sortable' => $this->sortable, 'width' => $this->width];
        $out['filter'] = null === $kind ? null : ['kind' => $kind->value, 'options' => \in_array($kind, [FilterKind::Select, FilterKind::Multi], true) ? $this->choices() : []];
        $out['detail'] = !$this->hideOnDetail;
        if ($this->hideOnForm) {
            $out['form'] = false;
        }
        if ($this->locked) {
            $out['ro'] = true;
        }
        if ($this->searchable) {
            $out['search'] = true;
        }

        return $out;
    }
}
