<?php

declare(strict_types=1);

namespace Itk\Cms\Field;

/** How a filterable field filters a list: text (LIKE), select / multi (one / several values), toggle, dateRange, numberRange, relation. */
enum FilterKind: string
{
    case Text = 'text';
    case Select = 'select';
    case Multi = 'multi';
    case Toggle = 'toggle';
    case DateRange = 'dateRange';
    case NumberRange = 'numberRange';
    case Relation = 'relation';
}
