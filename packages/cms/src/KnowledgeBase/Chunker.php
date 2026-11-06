<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase;

use Itk\Cms\KnowledgeBase\Model\Block;
use Itk\Cms\KnowledgeBase\Model\Chunk;
use Itk\Cms\KnowledgeBase\Model\Source;

/**
 * Blocks into chunks. Pure. The rules:
 *   a chunk never spans two sections (sectionId of a hit is exact)
 *   whole blocks of one section are packed greedily up to TARGET characters; a block (a repeater row, a subheading run) is split only when it alone
 *     is over MAX: at paragraphs, then at sentences, with one sentence of overlap
 *   a chunk under MIN is merged into the chunk before it in the same section when the result stays under MAX
 *   identical lines inside a chunk are kept once (an image alt that repeats a caption)
 *   the input is "{title}\n{heading}\n\n{text}": short texts embed next to what they are about. Only the document's own title, never a parent's,
 *     so a rename elsewhere never stales this document without an event
 * Characters, not tokens: about 300 to 500 tokens of Russian per chunk, far under the 8191-token input limit, without a tokenizer.
 *
 * @internal
 */
final class Chunker
{
    public const TARGET = 1200;
    public const MAX = 2000;
    public const MIN = 200;

    /**
     * @param list<Block> $blocks
     *
     * @return list<Chunk> ordinals 0..n-1
     */
    public static function chunk(Source $source, array $blocks): array
    {
        /** @var list<array{section: ?string, type: ?string, field: string, heading: ?string, lines: list<string>}> $chunks */
        $chunks = [];
        $section = false;
        $open = null;
        foreach ($blocks as $block) {
            if ($block->sectionId !== $section) {
                $section = $block->sectionId;
                $open = null;
            }
            foreach (self::pieces($block) as $i => $lines) {
                $text = \implode("\n", $lines);
                if (null !== $open && 0 === $i && \mb_strlen(\implode("\n", $chunks[$open]['lines'])) + \mb_strlen($text) + 2 <= self::TARGET) {
                    $chunks[$open]['lines'] = self::append($chunks[$open], $block->heading, $lines);
                    continue;
                }
                $chunks[] = ['section' => $block->sectionId, 'type' => $block->sectionType, 'field' => $block->field, 'heading' => $block->heading, 'lines' => $lines];
                $open = \count($chunks) - 1;
            }
        }
        for ($i = \count($chunks) - 1; $i > 0; --$i) {
            $short = \mb_strlen(\implode("\n", $chunks[$i]['lines']));
            $before = \mb_strlen(\implode("\n", $chunks[$i - 1]['lines']));
            if ($short < self::MIN && $chunks[$i]['section'] === $chunks[$i - 1]['section'] && $before + $short + 2 <= self::MAX) {
                $chunks[$i - 1]['lines'] = self::append($chunks[$i - 1], $chunks[$i]['heading'], $chunks[$i]['lines']);
                \array_splice($chunks, $i, 1);
            }
        }

        $out = [];
        foreach ($chunks as $ordinal => $c) {
            $text = \implode("\n", \array_values(\array_unique($c['lines'])));
            $input = $source->title . "\n" . ($c['heading'] ?? '') . "\n\n" . $text;
            $out[] = new Chunk($ordinal, $c['section'], $c['type'], $c['field'], $c['heading'], $text, $input, \hash('sha256', $input));
        }

        return $out;
    }

    /**
     * The lines of a chunk with more lines after them, the new heading written first when it differs from the chunk's own.
     *
     * @param array{heading: ?string, lines: list<string>} $chunk
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function append(array $chunk, ?string $heading, array $lines): array
    {
        return [...$chunk['lines'], ...(null !== $heading && $heading !== $chunk['heading'] ? [$heading] : []), ...$lines];
    }

    /**
     * A block as lists of lines: the whole block, or, when it alone is over MAX, parts of up to TARGET cut at paragraphs, then at sentences with one
     * sentence of overlap.
     *
     * @return list<list<string>>
     */
    private static function pieces(Block $block): array
    {
        $lines = $block->paragraphs;
        if (\mb_strlen(\implode("\n", $lines)) <= self::MAX) {
            return [$lines];
        }
        $pieces = [];
        $current = [];
        $length = 0;
        foreach ($lines as $paragraph) {
            foreach (self::sentences($paragraph) as $part) {
                $size = \mb_strlen($part);
                if ([] !== $current && $length + $size + 1 > self::TARGET) {
                    $pieces[] = $current;
                    $last = $current[\count($current) - 1];
                    $current = \mb_strlen($last) < self::TARGET / 2 ? [$last] : [];
                    $length = [] === $current ? 0 : \mb_strlen($last);
                }
                $current[] = $part;
                $length += $size + 1;
            }
        }
        if ([] !== $current) {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * A paragraph as one line when it fits TARGET, else its sentences (each at most TARGET: a run of text without a sentence end is cut at a space).
     *
     * @return list<string>
     */
    private static function sentences(string $paragraph): array
    {
        if (\mb_strlen($paragraph) <= self::TARGET) {
            return [$paragraph];
        }
        $out = [];
        foreach (\preg_split('/(?<=[.!?…])\s+/u', $paragraph) ?: [$paragraph] as $sentence) {
            while (\mb_strlen($sentence) > self::TARGET) {
                $cut = \mb_strrpos(\mb_substr($sentence, 0, self::TARGET), ' ');
                $cut = false === $cut || $cut < self::TARGET / 2 ? self::TARGET : $cut;
                $out[] = \trim(\mb_substr($sentence, 0, $cut));
                $sentence = \trim(\mb_substr($sentence, $cut));
            }
            if ('' !== $sentence) {
                $out[] = $sentence;
            }
        }

        return $out;
    }
}
