<?php

declare(strict_types=1);

namespace Itk\Cms\Resource;

use Itk\Cms\Field\FieldInterface;
use Itk\Cms\Security\Viewer;

/**
 * An action a person can run in a collection.
 *
 *   Action::make('Скопировать', 'duplicate')->row()->icon('copy')
 *       ->handle(static fn (ActionContext $c): ActionResult => ActionResult::message('Готово')->reload());
 *   Action::make('Закрыть набор', 'close')->bulk()->danger()->confirm('Закрыть набор?')
 *       ->modal('Причина', Textarea::make('Причина', 'reason')->required())->handle(...);
 *
 * An action can appear in the row menu (row()), the bulk toolbar (bulk()), the list header (top()),
 * or the record form (form()). Several places are allowed. The default is row().
 * Only an action with top() can run without records.
 * canSee() controls visibility. requires() selects the required collection right (edit by default).
 * permission() adds a separate right to the role matrix, `action:<resource>:<key>`.
 */
final class Action
{
    public const PLACES = ['row', 'bulk', 'top', 'form'];

    /** @var list<string> */
    private array $places = [];
    private ?string $icon = null;
    private bool $danger = false;
    private ?string $confirm = null;
    private ?string $modalTitle = null;
    /** @var list<FieldInterface> */
    private array $modalFields = [];
    private string|\Closure|null $canSee = null;
    private string $requires = 'edit';
    private bool $permission = false;
    private ?\Closure $handler = null;
    private bool $builtin = false;

    private function __construct(
        private readonly string $label,
        private readonly string $key,
    ) {
        if (!\preg_match('/^[a-z0-9][a-z0-9_\-]*$/', $key)) {
            throw new \InvalidArgumentException(\sprintf('The key of the action «%s» must be Latin letters, digits, _ and -: "%s".', $label, $key));
        }
    }

    public static function make(string $label, string $key): self
    {
        return new self($label, $key);
    }

    /** The actions every collection has («Опубликовать выбранные» …): the core runs them itself. */
    public static function builtin(string $label, string $key, string $right, bool $danger = false, ?string $confirm = null): self
    {
        $a = new self($label, $key);
        $a->builtin = true;
        $a->places = ['bulk'];
        $a->requires = $right;
        $a->danger = $danger;
        $a->confirm = $confirm;

        return $a;
    }

    public function row(): self
    {
        return $this->place('row');
    }

    public function bulk(): self
    {
        return $this->place('bulk');
    }

    public function top(): self
    {
        return $this->place('top');
    }

    public function form(): self
    {
        return $this->place('form');
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    /** Red button. */
    public function danger(bool $danger = true): self
    {
        $this->danger = $danger;

        return $this;
    }

    /** Ask «Вы уверены?» with this text before running. */
    public function confirm(string $text): self
    {
        $this->confirm = $text;

        return $this;
    }

    /** Ask for values before running; they arrive in ActionContext::input(), checked against the fields. */
    public function modal(string $title, FieldInterface ...$fields): self
    {
        $this->modalTitle = $title;
        $this->modalFields = \array_values($fields);

        return $this;
    }

    /** Who sees the action: a permission area / right as a string, or fn (Viewer $v): bool. */
    public function canSee(string|\Closure $who): self
    {
        $this->canSee = $who;

        return $this;
    }

    /** The collection right required to run the action: view | edit | publish | delete. Default: edit. */
    public function requires(string $right): self
    {
        $this->requires = $right;

        return $this;
    }

    /** A separate line «Действие» in the role matrix; only the roles that tick it can run the action. */
    public function permission(bool $permission = true): self
    {
        $this->permission = $permission;

        return $this;
    }

    /** @param callable(ActionContext): ActionResult $handler */
    public function handle(callable $handler): self
    {
        $this->handler = $handler(...);

        return $this;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** @return list<string> */
    public function places(): array
    {
        return [] === $this->places ? ['row'] : $this->places;
    }

    public function isBuiltin(): bool
    {
        return $this->builtin;
    }

    public function right(): string
    {
        return $this->requires;
    }

    public function hasPermission(): bool
    {
        return $this->permission;
    }

    /** @return list<FieldInterface> */
    public function modalFields(): array
    {
        return $this->modalFields;
    }

    public function visibleTo(Viewer $viewer): bool
    {
        if (null === $this->canSee) {
            return true;
        }

        return \is_string($this->canSee) ? $viewer->allows($this->canSee) : (bool) ($this->canSee)($viewer);
    }

    public function run(ActionContext $context): ActionResult
    {
        if (null === $this->handler) {
            throw new \LogicException(\sprintf('The action «%s» has no handler: add ->handle(fn (ActionContext $c): ActionResult => …).', $this->key));
        }
        $result = ($this->handler)($context);
        if (!$result instanceof ActionResult) {
            throw new \LogicException(\sprintf('The handler of the action «%s» must return an ActionResult.', $this->key));
        }

        return $result;
    }

    /** @return array<string, mixed> the action as the admin reads it */
    public function toArray(): array
    {
        $out = ['key' => $this->key, 'label' => $this->label, 'places' => $this->places(), 'danger' => $this->danger];
        if (null !== $this->icon) {
            $out['icon'] = $this->icon;
        }
        if (null !== $this->confirm) {
            $out['confirm'] = $this->confirm;
        }
        if (null !== $this->modalTitle) {
            $out['modal'] = ['title' => $this->modalTitle, 'fields' => \array_map(static fn (FieldInterface $f): array => $f->toArray(), $this->modalFields)];
        }

        return $out;
    }

    private function place(string $place): self
    {
        if (!\in_array($place, $this->places, true)) {
            $this->places[] = $place;
        }

        return $this;
    }
}
