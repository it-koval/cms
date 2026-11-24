<?php

declare(strict_types=1);

namespace Itk\Cms\Schema;

use Itk\Cms\Field\FieldInterface;
use Itk\Cms\Field\FormFields;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Security\Viewer;

/** A section type: the schema that drives the sidebar form, validation, the block library and the preview. */
final class SectionType
{
    /** Identifier of the group that carries the page's H1; the block library of the admin shows a label of its own for it, MCP keeps the identifier. */
    public const FIRST_SCREEN = 'Первый экран'; // i18n-ignore: a service identifier, matched against the group of a section and the value of the library filter

    private ?string $group = null;
    private ?bool $firstScreen = null;
    private string $short;
    private string $tone = 'white';
    private int $height = 90;
    private string $silhouette = 'text';
    private string $about = '';
    private ?string $h1Field = null;
    private ?string $templatePath = null;
    /** @var list<FieldInterface> */
    private array $fields = [];

    public function __construct(private readonly string $key, private readonly string $name)
    {
        $this->short = $name;
    }

    /**
     * Group in the block library; «Первый экран» sections carry the page's H1.
     * The default group is not stored: it is read in the language of the person who asks (a class written in any language of the interface means the same group).
     */
    public function group(string $group): self
    {
        $this->group = \in_array($group, I18n::variants('model.section_group_default'), true) ? null : $group;

        return $this;
    }

    /** Name on the canvas toolbar and in «Структура». */
    public function short(string $short): self
    {
        $this->short = $short;

        return $this;
    }

    /** Tone of the thumbnail: white | gray | dark | photo. */
    public function tone(string $tone): self
    {
        $this->tone = $tone;

        return $this;
    }

    public function height(int $height): self
    {
        $this->height = $height;

        return $this;
    }

    /** Silhouette kind for the thumbnail (admin preview/silhouettes.js). */
    public function silhouette(string $silhouette): self
    {
        $this->silhouette = $silhouette;

        return $this;
    }

    public function about(string $about): self
    {
        $this->about = $about;

        return $this;
    }

    /** Twig template that draws the section (default: sections/<key>.html.twig). */
    public function template(?string $path): self
    {
        $this->templatePath = $path;

        return $this;
    }

    public function fields(FieldInterface ...$fields): self
    {
        $this->fields = \array_values($fields);

        return $this;
    }

    /** The headline field that is the page's H1 when this section carries it (default: the first `headline` field of a «Первый экран» section). */
    public function h1(string $field): self
    {
        $this->h1Field = $field;

        return $this;
    }

    /** Key of the field that is the page's H1 when the section carries it; null for a section that never does. */
    public function h1Field(): ?string
    {
        if (!$this->isFirstScreen()) {
            return null;
        }
        if (null !== $this->h1Field) {
            return null !== $this->field($this->h1Field) ? $this->h1Field : null;
        }
        foreach ($this->fields as $f) {
            if ($f->isHeadline()) {
                return $f->key();
            }
        }

        return null;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function templatePath(): string
    {
        return $this->templatePath ?? 'sections/' . $this->key . '.html.twig';
    }

    public function groupName(): string
    {
        return $this->group ?? I18n::t('model.section_group_default');
    }

    public function isFirstScreen(): bool
    {
        return $this->firstScreen ?? self::FIRST_SCREEN === $this->group;
    }

    /** Explicitly marks a section as carrying the page H1, independently of its display group. */
    public function firstScreen(bool $firstScreen = true): self
    {
        $this->firstScreen = $firstScreen;

        return $this;
    }

    public function declaredH1Field(): ?string
    {
        return $this->h1Field;
    }

    /** @return list<FieldInterface> all fields incl. readonly notes */
    public function allFields(): array
    {
        return $this->fields;
    }

    /** @return list<FieldInterface> editable fields (readonly notes left out) */
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

    /**
     * @return array<string, mixed> the section as the admin reads it; with a viewer: only the fields he may see
     */
    public function toArray(?Viewer $viewer = null): array
    {
        $variants = [];
        foreach ($this->fields as $f) {
            $a = $f->toArray();
            if (!empty($a['variant'])) {
                foreach ($f->choices() as [$value, $label]) {
                    $variants[] = ['value' => $value, 'label' => $label];
                }
                break;
            }
        }

        return [
            'key' => $this->key,
            'name' => $this->name,
            'short' => $this->short,
            'group' => $this->groupName(),
            'firstScreen' => $this->isFirstScreen(),
            'tone' => $this->tone,
            'h' => $this->height,
            'sil' => $this->silhouette,
            'about' => $this->about,
            'h1' => $this->h1Field(),
            'fields' => FormFields::toArray($this->fields, $viewer),
            'variants' => $variants,
        ];
    }
}
