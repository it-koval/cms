<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\Entity\Revision;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\RevisionRepository;
use Itk\Cms\Support\Json;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The version of a draft, for the check of the editor's autosave: the editor sends the version it loaded (`baseVersion`), and a save over a draft that
 * has changed since is refused with 409 `conflict` instead of silently overwriting the change of an assistant or of another person.
 *
 * The version is a hash of the content of the draft (for a page also its place in the tree and its template, which an autosave writes too), not a counter
 * or a time: it needs no column, two changes in the same second still differ, publishing leaves it alone (the draft is the same), and a person who puts
 * everything back as it was gets the old version back. jsonb reorders the keys of JSON, hence Json::canonical().
 *
 * The hash is keyed with the secret of the site: the version goes to everyone who may read the page or the record, and a bare hash of the whole draft would let
 * a person try values of a field hidden from them (`canSee`) against it, offline, and tell the right one.
 *
 * @internal
 */
final class DraftVersion
{
    public function __construct(
        private readonly RevisionRepository $revisions,
        #[Autowire(param: 'kernel.secret')]
        private readonly string $secret,
    ) {
    }

    /** @param array<string, mixed> $state everything a save of that draft would write */
    public function of(array $state): string
    {
        return \substr(\hash_hmac('sha256', Json::encode(Json::canonical($state)), $this->secret), 0, 16);
    }

    /**
     * 409 for a save made over a newer draft: tells the client what the draft is now and who changed it last (the history knows: a move in the tree is an entry too).
     *
     * @param string $kind Revision::PAGE | Revision::ITEM
     */
    public function stale(string $kind, string $id, string $locale, string $current): ContentException
    {
        $last = $this->revisions->latestChange($kind, $id, $locale);

        return ContentException::conflict(
            Revision::PAGE === $kind ? I18n::t('content.stale_page') : I18n::t('content.stale_item'),
            [
                'code' => 'conflict',
                'current' => [
                    'version' => $current,
                    'by' => $last?->userName,
                    'at' => $last?->updatedAt->format(\DATE_ATOM),
                    'via' => $last?->via,
                    'viaName' => $last?->viaName,
                ],
            ],
        );
    }
}
