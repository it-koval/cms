<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\I18n\I18n;

/**
 * The arithmetic of moving nodes in a tree (pages, records of a collection with tree()), the same for both: a node is `id => [parentId, position]`.
 * Given the tree as it is and the nodes the person named, it checks the result (no unknown parent, no node inside itself or inside its own branch) and
 * numbers the brothers of every touched parent 0, 1, 2… in the order the person set.
 *
 * @internal
 */
final class TreeMove
{
    /**
     * @param array<int|string, array{0: ?string, 1: int}> $current every node, in the order of the brothers (position, then age)
     * @param array<int|string, array{0: ?string, 1: int}> $want the nodes that were named
     *
     * @return array{parent: array<string, ?string>, position: array<string, int>, moved: list<string>, reordered: list<string>}
     *                                                                                                                           moved = nodes that got another parent; reordered = nodes that kept it but changed their place among the brothers
     */
    public static function resolve(array $current, array $want): array
    {
        $parent = [];
        $position = [];
        $index = [];
        foreach ($current as $id => [$p, $pos]) {
            $parent[(string) $id] = $p;
            $position[(string) $id] = $pos;
            $index[(string) $id] = \count($index);
        }
        $before = self::places($parent, $position, $index);
        foreach ($want as $id => [$p, $pos]) {
            $id = (string) $id;
            if (!\array_key_exists($id, $parent)) {
                throw ContentException::notFound(I18n::t('content.move_source_missing'));
            }
            if (null !== $p && !\array_key_exists($p, $parent)) {
                throw ContentException::invalid(I18n::t('content.move_target_missing'));
            }
            if ($p === $id) {
                throw ContentException::invalid(I18n::t('content.move_into_self'));
            }
            $parent[$id] = $p;
            $position[$id] = $pos;
        }
        foreach (\array_keys($parent) as $id) {
            $id = (string) $id;
            $guard = 0;
            for ($cur = $parent[$id]; null !== $cur; $cur = $parent[$cur] ?? null) {
                if ($cur === $id || ++$guard > \count($parent)) {
                    throw ContentException::invalid(I18n::t('content.move_into_own_branch'));
                }
            }
        }

        // the brothers of every parent that got a node or lost one are numbered anew
        $touched = [];
        foreach ($want as $id => [$p]) {
            $touched[self::group($p)] = true;
            $touched[self::group($current[$id][0] ?? null)] = true;
        }
        $groups = [];
        foreach ($parent as $id => $p) {
            $groups[self::group($p)][] = (string) $id;
        }
        $named = [];
        foreach (\array_keys($want) as $id) {
            $named[(string) $id] = true;
        }
        foreach ($groups as $g => $ids) {
            if (!isset($touched[$g])) {
                continue;
            }
            // a named node goes before an unnamed brother with the same place: it was put there by hand
            \usort($ids, static fn (string $a, string $b): int => [$position[$a], isset($named[$a]) ? 0 : 1, $index[$a]] <=> [$position[$b], isset($named[$b]) ? 0 : 1, $index[$b]]);
            foreach ($ids as $n => $id) {
                $position[$id] = $n;
            }
        }
        $after = self::places($parent, $position, $index);
        $moved = [];
        $reordered = [];
        foreach ($want as $id => [$p]) {
            if (($current[$id][0] ?? null) !== $p) {
                $moved[] = (string) $id;
            }
        }
        foreach ($after as $id => $place) {
            if (!\in_array($id, $moved, true) && isset($touched[self::group($parent[$id])]) && ($before[$id] ?? -1) !== $place) {
                $reordered[] = $id;
            }
        }

        return ['parent' => $parent, 'position' => $position, 'moved' => $moved, 'reordered' => $reordered];
    }

    /**
     * The place of every node among its brothers.
     *
     * @param array<string, ?string> $parent
     * @param array<string, int> $position
     * @param array<string, int> $index
     *
     * @return array<string, int>
     */
    private static function places(array $parent, array $position, array $index): array
    {
        $groups = [];
        foreach ($parent as $id => $p) {
            $groups[self::group($p)][] = (string) $id;
        }
        $out = [];
        foreach ($groups as $ids) {
            \usort($ids, static fn (string $a, string $b): int => [$position[$a], $index[$a]] <=> [$position[$b], $index[$b]]);
            foreach ($ids as $n => $id) {
                $out[$id] = $n;
            }
        }

        return $out;
    }

    private static function group(?string $parent): string
    {
        return null === $parent ? '' : 'p:' . $parent;
    }
}
