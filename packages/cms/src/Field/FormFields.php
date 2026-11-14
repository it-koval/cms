<?php

declare(strict_types=1);

namespace Itk\Cms\Field;

use Itk\Cms\Security\Viewer;

/**
 * The fields of a form as the admin reads them. The fields the viewer may not see are left out; a heading (section()) of a left-out field
 * moves to the next field that stays, unless that one has a heading of its own, so the fields after it do not end up under the previous part.
 * Only what is written for the admin changes, the field objects are not touched.
 *
 * @internal
 */
final class FormFields
{
    /**
     * @param list<FieldInterface> $fields
     *
     * @return list<array<string, mixed>>
     */
    public static function toArray(array $fields, ?Viewer $viewer = null): array
    {
        $out = [];
        $carried = null;
        foreach ($fields as $f) {
            $a = $f->toArray();
            if (null !== $viewer && !$f->visibleTo($viewer)) {
                if (isset($a['section']) && '' !== $a['section']) {
                    $carried = $a['section'];
                }
                continue;
            }
            if (isset($a['section']) && '' !== $a['section']) {
                $carried = null;
            } elseif (null !== $carried) {
                $a['section'] = $carried;
                $carried = null;
            }
            $out[] = $a;
        }

        return $out;
    }
}
