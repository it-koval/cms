<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Itk\Cms\Repository\RevisionRepository;

/**
 * One entry of the history of a page, a record of a collection, a group of settings or a menu: who changed what and when, and the whole
 * version as it was afterwards. The autosaves of one person are merged into one entry (`updatedAt` moves, `createdAt` stays).
 *
 * snapshot: page      { template, parentId, status, draft: { title, url, seo, sections, fields } }
 *           item      { status, draft: { …fields } }, and parentId in a collection with a tree (entries older than that have none)
 *           settings  { key: value, … } of one group        menu  { name, depth, items }
 *           a deleted page { page: { id, parentId, position, template, createdAt }, versions: { locale: { draft, published, status, … } } }
 *           a deleted record { position, parentId, createdAt, translations: { locale: { draft, published, status, … } } }
 * summary:  the changes against the entry before: [{ kind, name?, label, val, old, key?, sec? }] (the format of ChangeDetector)
 *
 * @internal
 */
#[ORM\Entity(repositoryClass: RevisionRepository::class)]
#[ORM\Table(name: 'itk_revision')]
#[ORM\Index(name: 'itk_revision_object_idx', columns: ['resource_kind', 'resource_id', 'locale'])]
#[ORM\Index(name: 'itk_revision_action_idx', columns: ['action', 'created_at'])]
class Revision
{
    public const PAGE = 'page';
    public const ITEM = 'item';
    public const SETTINGS = 'settings';
    public const MENU = 'menu';
    public const KINDS = [self::PAGE, self::ITEM, self::SETTINGS, self::MENU];

    public const EDIT = 'edit';
    public const PUBLISH = 'publish';
    public const UNPUBLISH = 'unpublish';
    public const REVERT = 'revert';
    public const DELETE = 'delete';
    public const DELETE_TRANSLATION = 'delete_locale';
    public const RESTORE = 'restore';
    public const CREATE = 'create';
    public const ACTIONS = [self::EDIT, self::PUBLISH, self::UNPUBLISH, self::REVERT, self::DELETE, self::DELETE_TRANSLATION, self::RESTORE, self::CREATE];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    public ?int $id = null;

    /** page | item | settings | menu */
    #[ORM\Column(name: 'resource_kind', type: Types::STRING, length: 16)]
    public string $kind;

    /** Id of the page or record; the key of the settings group or of the menu area. */
    #[ORM\Column(name: 'resource_id', type: Types::STRING, length: 64)]
    public string $resourceId;

    /** The template of a page, the collection of a record, the group of settings; for the rest the same as resourceId. */
    #[ORM\Column(name: 'resource_key', type: Types::STRING, length: 64)]
    public string $resourceKey;

    #[ORM\Column(type: Types::STRING, length: 8)]
    public string $locale;

    #[ORM\Column(type: Types::STRING, length: 16)]
    public string $action;

    /** Who did it; null for the system (console, import, queue). There is no foreign key: the history outlives the person. */
    #[ORM\Column(name: 'user_id', type: Types::STRING, length: 36, nullable: true)]
    public ?string $userId = null;

    #[ORM\Column(name: 'user_name', type: Types::STRING, length: 180, nullable: true)]
    public ?string $userName = null;

    /** Through what the change was made: null = by hand in the admin, 'mcp' = by an AI assistant (the key's name is in `viaName`). */
    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    public ?string $via = null;

    #[ORM\Column(name: 'via_name', type: Types::STRING, length: 120, nullable: true)]
    public ?string $viaName = null;

    /** The name of the page or record at that moment (the trash lists it without reading the snapshot). */
    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $title = '';

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $snapshot = [];

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $summary = [];

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    /** The last autosave merged into this entry. */
    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $updatedAt;

    public function __construct(string $kind, string $resourceId, string $resourceKey, string $locale, string $action)
    {
        $this->kind = $kind;
        $this->resourceId = $resourceId;
        $this->resourceKey = $resourceKey;
        $this->locale = $locale;
        $this->action = $action;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    /** The version that was there before the history began: the first thing recorded for an object that already existed. */
    public function isBaseline(): bool
    {
        return 'baseline' === ($this->summary[0]['kind'] ?? null);
    }
}
