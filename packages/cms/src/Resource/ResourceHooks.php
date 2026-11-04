<?php

declare(strict_types=1);

namespace Itk\Cms\Resource;

/**
 * The hooks a CollectionResource / TemplateResource may override; the core calls them (after the Symfony event of the same moment).
 * A `before…` hook that throws Itk\Cms\Content\ContentException cancels the change and the editor sees its message.
 */
trait ResourceHooks
{
    /** The core reads this section for the first time in the request (list or one record). */
    public function onLoad(Context $ctx): void
    {
    }

    /**
     * @param array<string, mixed> $data what is about to be stored in the new record
     *
     * @return array<string, mixed> the data to store
     */
    public function beforeCreating(array $data, Context $ctx): array
    {
        return $data;
    }

    public function afterCreated(Record $record, Context $ctx): void
    {
    }

    /**
     * @param array<string, mixed> $data the incoming change (the fields that are being written)
     *
     * @return array<string, mixed> the change to apply
     */
    public function beforeUpdating(Record $record, array $data, Context $ctx): array
    {
        return $data;
    }

    public function afterUpdated(Record $record, Context $ctx): void
    {
    }

    public function beforeDeleting(Record $record, Context $ctx): void
    {
    }

    public function afterDeleted(Record $record, Context $ctx): void
    {
    }

    public function beforePublishing(Record $record, Context $ctx): void
    {
    }

    public function afterPublished(Record $record, Context $ctx): void
    {
    }

    public function beforeUnpublishing(Record $record, Context $ctx): void
    {
    }

    public function afterUnpublished(Record $record, Context $ctx): void
    {
    }

    /** @param list<Record> $records */
    public function beforeMassDeleting(array $records, Context $ctx): void
    {
    }

    /** @param list<Record> $records */
    public function afterMassDeleted(array $records, Context $ctx): void
    {
    }

    /** @param list<Record> $records */
    public function beforeMassPublishing(array $records, Context $ctx): void
    {
    }

    /** @param list<Record> $records */
    public function afterMassPublished(array $records, Context $ctx): void
    {
    }

    /** @param list<Record> $records */
    public function beforeMassUnpublishing(array $records, Context $ctx): void
    {
    }

    /** @param list<Record> $records */
    public function afterMassUnpublished(array $records, Context $ctx): void
    {
    }
}
