<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\Schema\Schema;

/**
 * The ONLY place on the server that decides what HTML may be stored or rendered. Mirrors admin/src/utils/html.js.
 *
 * modes:
 *   headline  inline only: <br> and <span class="grad"> (the gradient only when the site design has it). A block boundary becomes <br>.
 *   rich      the DESCRIPTION mode: <p> <br> <strong> <em> <a href> <ul> <ol> <li>.
 *   article   long texts: rich + <h2> <h3> <blockquote> <hr> <img src alt [width] [height] [data-align]> (width and height only as positive whole numbers; data-align: left, center or right)
 *             and a simple table: <table> <thead> <tbody> <tr> <th> <td> without attributes, except a numeric colspan / rowspan (2 and more) on <th> and <td>.
 *             A table is kept only at the top level of the text; a cell holds inline text only (<strong> <em> <a> <br>), blocks inside a cell become line breaks.
 *             The leading rows made of <th> cells only go to <thead>, the rest to <tbody> (so the editor, which has no <thead>, round-trips to the same HTML).
 */
final class HtmlSanitizer
{
    public const GRAD_CLASS = 'grad';

    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'svg', 'math', 'head', 'title'];
    private const BLOCK = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'section', 'article'];
    /** Tags that start a new line inside a table cell (a cell holds inline text only). */
    private const CELL_BLOCK = ['ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption'];
    private const URL_CONTROLS = '~[\x00-\x1F\x7F]~';
    private const SAFE_HREF = '~^(https?://|mailto:|tel:|/(?![/\\\\])|#)~i';
    private const SAFE_SRC = '~^(assets/[^"\'<>\s]+|/(?![/\\\\])[^"\'<>\s]+|https?://[^"\'<>\s]+|data:image/(png|jpe?g|webp|gif|avif);base64,[A-Za-z0-9+/=]+)$~i';

    public function __construct(private readonly Schema $schema)
    {
    }

    public static function escape(?string $s): string
    {
        return \strtr((string) $s, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;']);
    }

    /** Returns a safe HTML string. Rich mode guarantees block structure: loose inline runs are wrapped in <p>. */
    public function sanitize(mixed $html, string $mode = 'rich'): string
    {
        if ('headline' !== $mode && 'article' !== $mode) {
            $mode = 'rich';
        }
        if (null === $html || '' === $html || !\is_scalar($html)) {
            return '';
        }
        $body = $this->parse((string) $html);
        if ('headline' === $mode) {
            $out = $this->inline($body, 'headline');

            // keep at most one trailing break (the editor shows the empty line)
            return (string) \preg_replace_callback('/(<br>)+$/', static fn (array $m): string => \strlen($m[0]) > 4 ? '<br>' : $m[0], $out);
        }
        $out = '';
        $run = '';
        $flush = static function () use (&$out, &$run): void {
            if ('' !== \trim($run)) {
                $out .= '<p>' . $run . '</p>';
            }
            $run = '';
        };
        foreach ($body->childNodes as $n) {
            $tag = \XML_ELEMENT_NODE === $n->nodeType ? \strtolower($n->nodeName) : '';
            $asBlock = \in_array($tag, self::BLOCK, true) || 'ul' === $tag || 'ol' === $tag || ('article' === $mode && ('hr' === $tag || 'img' === $tag || 'table' === $tag));
            if (\XML_ELEMENT_NODE === $n->nodeType && $asBlock) {
                $flush();
                $out .= $this->walk($n, $mode);
            } else {
                $run .= $this->walk($n, $mode);
            }
        }
        $flush();

        return 'article' === $mode ? (string) \preg_replace('~(<p></p>)+$~', '', $out) : $out;
    }

    /** The first non-empty line of a headline as plain text (a heading's <br> separates lines): the page name a multi-line heading stands for. */
    public function firstLine(mixed $html): string
    {
        foreach (\explode("\n", $this->toText($html)) as $line) {
            $line = \trim((string) \preg_replace('/\s+/u', ' ', $line));
            if ('' !== $line) {
                return $line;
            }
        }

        return '';
    }

    /** Plain text of an HTML value: <br> and block ends become "\n". */
    public function toText(mixed $html): string
    {
        if (null === $html || '' === $html || !\is_scalar($html)) {
            return '';
        }
        $body = $this->parse((string) $html);
        $out = '';
        $go = static function (\DOMNode $n) use (&$out, &$go): void {
            if (\XML_TEXT_NODE === $n->nodeType || \XML_CDATA_SECTION_NODE === $n->nodeType) {
                $out .= $n->nodeValue;

                return;
            }
            if (\XML_ELEMENT_NODE !== $n->nodeType) {
                return;
            }
            $tag = \strtolower($n->nodeName);
            if (\in_array($tag, self::DROP, true)) {
                return;
            }
            if ('br' === $tag) {
                $out .= "\n";

                return;
            }
            foreach ($n->childNodes as $c) {
                $go($c);
            }
            if (\in_array($tag, ['p', 'li', 'div', 'h2', 'h3', 'blockquote', 'tr'], true)) {
                $out .= "\n";
            } elseif (\in_array($tag, ['td', 'th'], true)) {
                $out .= ' ';
            }
        };
        foreach ($body->childNodes as $n) {
            $go($n);
        }

        return (string) \preg_replace('/\n+$/', '', $out);
    }

    /** One line of plain text: toText() with every line break and run of whitespace collapsed to one space (a two-line headline reads as one sentence). */
    public function oneLine(mixed $html): string
    {
        return \trim((string) \preg_replace('/\s+/u', ' ', $this->toText($html)));
    }

    /**
     * The text of a Description or an Article as runs under their subheadings, for the knowledge base. The first run has heading null (the text before
     * the first subheading) and is left out when it is empty.
     *   h1 … h4                        start a new run; their text is its heading
     *   p, li, figcaption              one paragraph each (a quote with paragraphs: one per paragraph; a list item with a nested list: its own text, then the nested items)
     *   tr                             one paragraph, the cells joined with " | "
     *   hr, img and the DROP tags      nothing
     * Whitespace is collapsed inside a paragraph, a <br> is a space, an empty paragraph is left out.
     *
     * @return list<array{heading: ?string, paragraphs: list<string>}>
     */
    public function runs(mixed $html): array
    {
        if (null === $html || '' === $html || !\is_scalar($html)) {
            return [];
        }
        /** @var list<array{heading: ?string, paragraphs: list<string>}> $runs */
        $runs = [['heading' => null, 'paragraphs' => []]];
        $inline = '';
        $paragraph = static function (string $text) use (&$runs): void {
            $text = \trim((string) \preg_replace('/\s+/u', ' ', $text));
            if ('' !== $text) {
                $runs[\count($runs) - 1]['paragraphs'][] = $text;
            }
        };
        $flush = static function () use (&$inline, $paragraph): void {
            $paragraph($inline);
            $inline = '';
        };
        $walk = function (\DOMNode $node) use (&$walk, &$runs, &$inline, $paragraph, $flush): void {
            foreach ($node->childNodes as $n) {
                if (\XML_TEXT_NODE === $n->nodeType || \XML_CDATA_SECTION_NODE === $n->nodeType) {
                    $inline .= $n->nodeValue;
                    continue;
                }
                if (!$n instanceof \DOMElement) {
                    continue;
                }
                $tag = \strtolower($n->nodeName);
                if (\in_array($tag, self::DROP, true) || 'hr' === $tag || 'img' === $tag) {
                    continue;
                }
                if ('br' === $tag) {
                    $inline .= ' ';
                    continue;
                }
                if (\in_array($tag, ['h1', 'h2', 'h3', 'h4'], true)) {
                    $flush();
                    $runs[] = ['heading' => $this->oneLine($this->innerHtml($n)), 'paragraphs' => []];
                    continue;
                }
                if ('tr' === $tag) {
                    $flush();
                    $cells = [];
                    foreach ($n->childNodes as $cell) {
                        if ($cell instanceof \DOMElement && \in_array(\strtolower($cell->nodeName), ['td', 'th'], true)) {
                            $cells[] = $this->oneLine($this->innerHtml($cell));
                        }
                    }
                    $paragraph(\implode(' | ', \array_filter($cells, static fn (string $c): bool => '' !== $c)));
                    continue;
                }
                if (!$this->holdsBlocks($n)) {
                    if (\in_array($tag, ['p', 'li', 'figcaption'], true)) {
                        $flush();
                        $paragraph($this->toText($this->innerHtml($n)));
                    } else {
                        $inline .= $this->toText($this->innerHtml($n));
                    }
                    continue;
                }
                $flush();
                $walk($n);
                $flush();
            }
        };
        $walk($this->parse((string) $html));
        $flush();
        if (null === $runs[0]['heading'] && [] === $runs[0]['paragraphs']) {
            \array_shift($runs);
        }

        return $runs;
    }

    /** Whether a node contains an element that opens a paragraph or a run of runs(). */
    private function holdsBlocks(\DOMElement $node): bool
    {
        foreach ($node->getElementsByTagName('*') as $child) {
            if (\in_array(\strtolower($child->nodeName), ['p', 'li', 'figcaption', 'tr', 'h1', 'h2', 'h3', 'h4', 'blockquote', 'ul', 'ol', 'table'], true)) {
                return true;
            }
        }

        return false;
    }

    private function innerHtml(\DOMElement $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= (string) $node->ownerDocument?->saveHTML($child);
        }

        return $html;
    }

    public function isEmpty(mixed $html): bool
    {
        return '' === \trim($this->toText($html));
    }

    private function parse(string $html): \DOMElement
    {
        $doc = new \DOMDocument();
        $prev = \libxml_use_internal_errors(true);
        $doc->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>', \LIBXML_NOERROR | \LIBXML_NOWARNING);
        \libxml_clear_errors();
        \libxml_use_internal_errors($prev);
        /** @var \DOMElement $body */
        $body = $doc->getElementsByTagName('body')->item(0);

        return $body;
    }

    private function inline(\DOMNode $node, string $mode): string
    {
        $out = '';
        foreach ($node->childNodes as $n) {
            $out .= $this->walk($n, $mode);
        }

        return $out;
    }

    private function walk(\DOMNode $node, string $mode): string
    {
        if (\XML_TEXT_NODE === $node->nodeType || \XML_CDATA_SECTION_NODE === $node->nodeType) {
            return self::escape($node->nodeValue);
        }
        if (!$node instanceof \DOMElement) {
            return '';
        }
        $tag = \strtolower($node->nodeName);
        if (\in_array($tag, self::DROP, true)) {
            return '';
        }
        if ('br' === $tag) {
            return '<br>';
        }
        if ('article' === $mode && 'table' === $tag) {
            return $this->table($node);
        }
        $kids = $this->inline($node, $mode);
        if ('span' === $tag) {
            $isGrad = \in_array(self::GRAD_CLASS, \preg_split('/\s+/', \trim($node->getAttribute('class'))) ?: [], true);
            if ('headline' !== $mode || !$this->schema->allowsGradient() || !$isGrad) {
                return $kids;
            }
            // a line break stays OUTSIDE the gradient (as on the live site): every line gets its own span
            $parts = \explode('<br>', $kids);
            foreach ($parts as $i => $part) {
                $parts[$i] = '' === \trim($part) ? $part : '<span class="' . self::GRAD_CLASS . '">' . $part . '</span>';
            }

            return \implode('<br>', $parts);
        }
        if ('headline' === $mode || 'cell' === $mode) {
            if ($this->isLineBlock($tag, $mode)) {
                // a block that follows text on the same line starts a new line (<span>a<div>b</div></span> is two lines, as in the browser)
                $prev = $node->previousSibling;
                $break = null !== $prev && (
                    (\XML_TEXT_NODE === $prev->nodeType && '' !== \trim((string) $prev->nodeValue))
                    || (\XML_ELEMENT_NODE === $prev->nodeType && 'br' !== \strtolower($prev->nodeName) && !$this->isLineBlock(\strtolower($prev->nodeName), $mode))
                );

                return ($break ? '<br>' : '') . $kids . (null !== $node->nextSibling ? '<br>' : '');
            }
            if ('headline' === $mode) {
                return $kids;
            }
        }
        if ('article' === $mode) {
            switch ($tag) {
                case 'h1':
                case 'h2':
                    return '' !== \trim($kids) ? "<h2>$kids</h2>" : '';
                case 'h3':
                case 'h4':
                case 'h5':
                case 'h6':
                    return '' !== \trim($kids) ? "<h3>$kids</h3>" : '';
                case 'blockquote':
                    return '' !== \trim($kids) ? '<blockquote>' . (\str_starts_with($kids, '<p>') ? $kids : "<p>$kids</p>") . '</blockquote>' : '';
                case 'hr':
                    return '<hr>';
                case 'img':
                    $src = \trim($node->getAttribute('src'));
                    if (\preg_match(self::URL_CONTROLS, $src) || !\preg_match(self::SAFE_SRC, $src)) {
                        return '';
                    }

                    $size = '';
                    foreach (['width', 'height'] as $dimension) {
                        $value = \trim($node->getAttribute($dimension));
                        if (\preg_match('/^\d{1,5}$/', $value) && (int) $value > 0) {
                            $size .= ' ' . $dimension . '="' . (int) $value . '"';
                        }
                    }

                    $align = \strtolower(\trim($node->getAttribute('data-align')));
                    if (\in_array($align, ['left', 'center', 'right'], true)) {
                        $size .= ' data-align="' . $align . '"';
                    }

                    return '<img src="' . self::escape($src) . '" alt="' . self::escape(\trim($node->getAttribute('alt'))) . '"' . $size . '>';
                default:
                    break;
            }
        }
        switch ($tag) {
            case 'strong':
            case 'b':
                return '' !== $kids ? "<strong>$kids</strong>" : '';
            case 'em':
            case 'i':
                return '' !== $kids ? "<em>$kids</em>" : '';
            case 'a':
                $href = \trim($node->getAttribute('href'));
                if (\preg_match(self::URL_CONTROLS, $href) || !\preg_match(self::SAFE_HREF, $href)) {
                    return $kids;
                }
                $ext = (bool) \preg_match('~^https?:~i', $href);

                return '<a href="' . self::escape($href) . '"' . ($ext ? ' target="_blank" rel="noopener noreferrer"' : '') . ">$kids</a>";
            case 'ul':
            case 'ol':
                return '' !== $kids ? "<$tag>$kids</$tag>" : '';
            case 'li':
                return "<li>$kids</li>";
            case 'p':
            case 'div':
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
            case 'blockquote':
            case 'section':
            case 'article':
            case 'pre':
                return "<p>$kids</p>";
            default:
                return $kids;
        }
    }

    private function isLineBlock(string $tag, string $mode): bool
    {
        return \in_array($tag, self::BLOCK, true) || ('cell' === $mode && \in_array($tag, self::CELL_BLOCK, true));
    }

    /** The inline HTML of a table cell: <strong> <em> <a> <br> and text; every block inside becomes a line break; nothing else survives. */
    private function cell(\DOMNode $cell): string
    {
        $html = (string) \preg_replace('~^(?:\s|<br>)+|(?:\s|<br>)+$~u', '', $this->inline($cell, 'cell'));

        return (string) \preg_replace('~(?:<br>\s*){2,}~u', '<br>', $html); // dropped images and rules must not leave empty lines
    }

    /** A table only at the top level of the text; anywhere else (in a paragraph, a quote, a list, another cell) its text is flattened into lines. */
    private function table(\DOMElement $table): string
    {
        if ('body' !== \strtolower((string) $table->parentNode?->nodeName)) {
            return $this->cell($table);
        }
        /** @var list<list<array{string, string}>> $rows */
        $rows = [];
        $collect = function (\DOMElement $holder, bool $top) use (&$collect, &$rows): void {
            foreach ($holder->childNodes as $c) {
                if (!$c instanceof \DOMElement) {
                    continue;
                }
                $tag = \strtolower($c->nodeName);
                if ('tr' === $tag) {
                    $cells = [];
                    foreach ($c->childNodes as $td) {
                        $name = $td instanceof \DOMElement ? \strtolower($td->nodeName) : '';
                        if ('td' === $name || 'th' === $name) {
                            $cells[] = [$name, $this->cellAttrs($td) . '>' . $this->cell($td)];
                        }
                    }
                    if ([] !== $cells) {
                        $rows[] = $cells;
                    }
                } elseif ($top && \in_array($tag, ['thead', 'tbody', 'tfoot'], true)) {
                    $collect($c, false);
                }
            }
        };
        $collect($table, true);
        if ([] === $rows) {
            return '';
        }
        $render = static function (array $set): string {
            $html = '';
            foreach ($set as $cells) {
                $html .= '<tr>';
                foreach ($cells as [$name, $inner]) {
                    $html .= "<$name$inner</$name>";
                }
                $html .= '</tr>';
            }

            return $html;
        };
        $head = [];
        while ([] !== $rows && !\array_filter($rows[0], static fn (array $c): bool => 'th' !== $c[0])) {
            $head[] = \array_shift($rows);
        }

        return '<table>' . ([] !== $head ? '<thead>' . $render($head) . '</thead>' : '') . ([] !== $rows ? '<tbody>' . $render($rows) . '</tbody>' : '') . '</table>';
    }

    /** Only a numeric colspan / rowspan of 2 and more survives (the attribute part of the opening tag, without ">"). */
    private function cellAttrs(\DOMElement $cell): string
    {
        $out = '';
        foreach (['colspan', 'rowspan'] as $name) {
            $value = \trim($cell->getAttribute($name));
            if (\preg_match('/^[1-9]\d{0,2}$/', $value) && (int) $value > 1) {
                $out .= ' ' . $name . '="' . (int) $value . '"';
            }
        }

        return $out;
    }
}
