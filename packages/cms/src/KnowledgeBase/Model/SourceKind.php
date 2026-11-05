<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Model;

/** What a knowledge-base document was made from: a language version of a page or of a collection item. Same words as RecordEvent::$kind. */
enum SourceKind: string
{
    case Page = 'page';
    case Item = 'item';
}
