<?php

declare(strict_types=1);

namespace Itk\Cms\Field;

/** Where something leads. Value: { kind: 'page'|'url'|'modal', page, url }. */
final class Link extends AbstractField
{
    protected function wireType(): string
    {
        return 'link';
    }
}
