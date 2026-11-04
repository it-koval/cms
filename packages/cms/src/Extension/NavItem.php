<?php

declare(strict_types=1);

namespace Itk\Cms\Extension;

use Itk\Cms\I18n\I18n;
use Itk\Cms\Security\Viewer;

/**
 * An item of the admin menu that is not a collection: a screen a plugin adds.
 *
 *   NavItem::make('Отчёты', '/x/reports')->group('Аналитика', icon: 'chart', order: 60)->icon('chart')->canSee('col:tours')
 *
 * `to` is a route of the admin (the part after #); a plugin registers the screen with window.ITK.registerPlugin({ panels: … }).
 */
final class NavItem
{
    private ?string $group = null;
    private ?string $groupIcon = null;
    private int $groupOrder = 800;
    private ?string $icon = null;
    private int $order = 100;
    private string|\Closure|null $canSee = null;

    private function __construct(
        private readonly string $label,
        private readonly string $to,
    ) {
    }

    public static function make(string $label, string $to): self
    {
        return new self($label, $to);
    }

    public function group(string $name, ?string $icon = null, int $order = 800): self
    {
        $this->group = $name;
        $this->groupIcon = $icon;
        $this->groupOrder = $order;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function order(int $order): self
    {
        $this->order = $order;

        return $this;
    }

    public function canSee(string|\Closure $who): self
    {
        $this->canSee = $who;

        return $this;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function to(): string
    {
        return $this->to;
    }

    public function groupName(): string
    {
        return $this->group ?? I18n::t('model.nav_group_plugins');
    }

    public function groupIcon(): ?string
    {
        return $this->groupIcon;
    }

    public function groupOrder(): int
    {
        return $this->groupOrder;
    }

    public function iconName(): ?string
    {
        return $this->icon;
    }

    public function position(): int
    {
        return $this->order;
    }

    public function visibleTo(Viewer $viewer): bool
    {
        if (null === $this->canSee) {
            return true;
        }

        return \is_string($this->canSee) ? $viewer->allows($this->canSee) : (bool) ($this->canSee)($viewer);
    }
}
