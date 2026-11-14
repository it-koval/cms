<?php

declare(strict_types=1);

namespace Itk\Cms\Resource;

use Itk\Cms\Content\CollectionService;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\FieldAccess;
use Itk\Cms\Content\FieldValues;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\Access;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Runs an action of a collection for the current person: checks that it is his to run, finds the records, checks the modal values, calls the handler. */
final class ActionRunner
{
    public const MAX_RECORDS = 500;

    public function __construct(
        private readonly Schema $schema,
        private readonly CollectionService $collections,
        private readonly FieldValues $values,
        private readonly FieldAccess $fields,
        private readonly Access $access,
    ) {
    }

    /**
     * @param list<string> $ids
     * @param array<string, mixed> $input
     */
    public function run(string $resource, string $actionKey, array $ids, array $input = [], ?string $locale = null): ActionResult
    {
        $c = $this->schema->collection($resource) ?? throw ContentException::notFound(I18n::t('model.action_collection_not_found'));
        $viewer = $this->access->viewer();
        if (!$c->isVisibleTo($viewer)) {
            throw new AccessDeniedException(I18n::t('model.action_no_permission'));
        }
        $action = $c->action($actionKey) ?? throw ContentException::notFound(I18n::t('model.action_unknown'));
        if (!$c->mayRun($action, $viewer)) {
            throw new AccessDeniedException(I18n::t('model.action_no_permission'));
        }
        if (\count($ids) > self::MAX_RECORDS) {
            throw ContentException::invalid(I18n::t('model.action_too_many', ['count' => self::MAX_RECORDS]));
        }
        if ([] === $ids && !\in_array('top', $action->places(), true)) {
            throw ContentException::invalid(I18n::t('model.action_select_records'));
        }
        $values = $this->checked($action, $input);
        $locale ??= $this->collections->mainLocale();

        if ($action->isBuiltin()) {
            return $this->builtin($resource, $actionKey, $ids, $locale);
        }
        $records = $this->collections->records($resource, $ids, $locale);

        return $action->run(new ActionContext($records, $values, $viewer, $resource, 'item', $locale));
    }

    /**
     * The values of the modal: the fields' own rules, required ones present.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function checked(Action $action, array $input): array
    {
        $fields = $this->fields->writable($action->modalFields());
        $values = $this->values->normalize($fields, $input);
        $errors = $this->values->validate($fields, $values);
        if ([] !== $errors) {
            throw ContentException::invalid(I18n::t('model.action_fill_required'), $errors);
        }

        return $values;
    }

    /** @param list<string> $ids */
    private function builtin(string $resource, string $key, array $ids, string $locale): ActionResult
    {
        return match ($key) {
            'publish-selected' => ActionResult::message(I18n::t('model.action_published', ['count' => $this->collections->massPublish($resource, $ids, $locale)]))->reload(),
            'unpublish-selected' => ActionResult::message(I18n::t('model.action_unpublished', ['count' => $this->collections->massUnpublish($resource, $ids, $locale)]))->reload(),
            'delete-selected' => ActionResult::message(I18n::t('model.action_deleted', ['count' => $this->collections->massRemove($resource, $ids)]))->reload(),
            default => throw ContentException::notFound(I18n::t('model.action_unknown')),
        };
    }
}
