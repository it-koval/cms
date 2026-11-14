<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Санитайзер текста статьи: простая таблица (table, thead, tbody, tr, th, td) без атрибутов, кроме числовых colspan/rowspan; в остальных режимах таблицы нет. */
final class RichTextTableTest extends KernelTestCase
{
    private function sanitizer(): HtmlSanitizer
    {
        self::bootKernel();

        return self::getContainer()->get(HtmlSanitizer::class);
    }

    /** @return iterable<string, array{string, string}> */
    public static function tables(): iterable
    {
        $plain = '<table><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>';
        yield 'canonical form is kept' => [$plain, $plain];
        yield 'what the editor writes (no thead, paragraphs in cells, extra attributes)' => [
            '<table style="min-width: 75px"><colgroup><col style="min-width: 25px"></colgroup><tbody><tr><th colspan="1" rowspan="1"><p>A</p></th><th colspan="1" rowspan="1"><p>B</p></th></tr><tr><td colspan="1" rowspan="1"><p>1</p></td><td colspan="1" rowspan="1"><p>2</p></td></tr></tbody></table>',
            $plain,
        ];
        yield 'rows without tbody' => ['<table><tr><td>1</td></tr></table>', '<table><tbody><tr><td>1</td></tr></tbody></table>'];
        yield 'only header rows' => ['<table><tr><th>A</th></tr></table>', '<table><thead><tr><th>A</th></tr></thead></table>'];
        yield 'a header cell in a later row stays a cell' => ['<table><tr><td>a</td></tr><tr><th>H</th></tr></table>', '<table><tbody><tr><td>a</td></tr><tr><th>H</th></tr></tbody></table>'];
        yield 'colspan and rowspan: numbers of 2 and more' => [
            '<table><tr><td colspan="2" rowspan="3">a</td><td colspan="1" rowspan="0">b</td><td colspan="x" rowspan="2&quot; onclick=&quot;y">c</td><td colspan="-2">d</td></tr></table>',
            '<table><tbody><tr><td colspan="2" rowspan="3">a</td><td>b</td><td>c</td><td>d</td></tr></tbody></table>',
        ];
        yield 'colspan on a row or a table is dropped' => ['<table colspan="2"><tr colspan="2"><td>a</td></tr></table>', '<table><tbody><tr><td>a</td></tr></tbody></table>'];
        yield 'style, class, id, onclick, width, align, scope are dropped' => [
            '<table class="t" style="width:100%" onclick="x()" border="1" width="500"><tbody class="b"><tr style="color:red" id="r"><th scope="col" align="left" style="background:red" class="h" onclick="x()">A</th><td data-x="1" width="10" height="5" valign="top" onmouseover="y()">1</td></tr></tbody></table>',
            '<table><tbody><tr><th>A</th><td>1</td></tr></tbody></table>',
        ];
        yield 'inline marks and links in cells, nothing else' => [
            '<table><tr><td><p>a <strong>b</strong> <em>c</em> <a href="https://x.test/" onclick="x()" style="color:red">d</a><br>e</p><p>f</p></td></tr></table>',
            '<table><tbody><tr><td>a <strong>b</strong> <em>c</em> <a href="https://x.test/" target="_blank" rel="noopener noreferrer">d</a><br>e<br>f</td></tr></tbody></table>',
        ];
        yield 'unsafe link in a cell loses the link' => ['<table><tr><td><a href="javascript:alert(1)">x</a></td></tr></table>', '<table><tbody><tr><td>x</td></tr></tbody></table>'];
        yield 'blocks, lists, headings, images and nested tables inside a cell become lines of text' => [
            '<table><tr><td><h3>H</h3><ul><li>one</li><li>two</li></ul><img src="/uploads/media/a.jpg" alt="x"><hr><table><tr><td>n1</td><td>n2</td></tr></table></td></tr></table>',
            '<table><tbody><tr><td>H<br>one<br>two<br>n1<br>n2</td></tr></tbody></table>',
        ];
        yield 'script and style in a cell are removed with their content' => ['<table><tr><td>a<script>alert(1)</script><style>x{}</style>b</td></tr></table>', '<table><tbody><tr><td>ab</td></tr></tbody></table>'];
        yield 'empty cell' => ['<table><tr><td><p></p></td><td></td></tr></table>', '<table><tbody><tr><td></td><td></td></tr></tbody></table>'];
        yield 'text is escaped' => ['<table><tr><td>&lt;b&gt; &amp; "q"</td></tr></table>', '<table><tbody><tr><td>&lt;b&gt; &amp; &quot;q&quot;</td></tr></tbody></table>'];
        yield 'caption and colgroup are dropped' => ['<table><caption>C</caption><colgroup><col></colgroup><tr><td>a</td></tr></table>', '<table><tbody><tr><td>a</td></tr></tbody></table>'];
        yield 'tfoot rows go to the body' => ['<table><tbody><tr><td>a</td></tr></tbody><tfoot><tr><td>z</td></tr></tfoot></table>', '<table><tbody><tr><td>a</td></tr><tr><td>z</td></tr></tbody></table>'];
        yield 'a table without cells is dropped' => ['<p>a</p><table></table><table><tr></tr></table><p>b</p>', '<p>a</p><p>b</p>'];
        yield 'a table between paragraphs is a block' => ['<p>a</p><table><tr><td>1</td></tr></table><p>b</p>', '<p>a</p><table><tbody><tr><td>1</td></tr></tbody></table><p>b</p>'];
        yield 'a table with a loose text in front is not wrapped in a paragraph' => ['x<table><tr><td>1</td></tr></table>', '<p>x</p><table><tbody><tr><td>1</td></tr></tbody></table>'];
    }

    #[Test]
    #[DataProvider('tables')]
    public function articleKeepsASimpleTable(string $in, string $expected): void
    {
        self::assertSame($expected, $this->sanitizer()->sanitize($in, 'article'));
    }

    #[Test]
    #[DataProvider('tables')]
    public function theRoundTripIsStable(string $in, string $unused): void
    {
        $once = $this->sanitizer()->sanitize($in, 'article');
        self::assertSame($once, $this->sanitizer()->sanitize($once, 'article'));
    }

    #[Test]
    public function strayTableParts(): void
    {
        $s = $this->sanitizer();
        // cells and rows outside a table are not a table: only the text stays, in a paragraph
        self::assertSame('<p>ab</p>', $s->sanitize('<tr><td>a</td><td>b</td></tr>', 'article'));
        // text and tags between the rows and cells are dropped
        self::assertSame('<table><tbody><tr><td>1</td></tr></tbody></table>', $s->sanitize('<table>junk<tr>junk<td>1</td><p>x</p></tr><div>y</div></table>', 'article'));
    }

    #[Test]
    public function aTableInsideAnotherBlockIsFlattened(): void
    {
        $s = $this->sanitizer();
        $out = $s->sanitize('<blockquote><table><tr><td>a</td><td>b</td></tr></table></blockquote>', 'article');
        self::assertStringNotContainsString('<table', $out);
        self::assertStringContainsString('a', $out);
        self::assertStringContainsString('b', $out);
        $out = $s->sanitize('<ul><li><table><tr><td>a</td></tr></table></li></ul>', 'article');
        self::assertStringNotContainsString('<table', $out);
    }

    #[Test]
    public function otherModesHaveNoTables(): void
    {
        $s = $this->sanitizer();
        $html = '<p>t</p><table style="x" onclick="y"><thead><tr><th>H</th></tr></thead><tbody><tr><td colspan="2">c</td></tr></tbody></table>';
        foreach (['rich', 'headline'] as $mode) {
            $out = $s->sanitize($html, $mode);
            self::assertDoesNotMatchRegularExpression('~<(table|thead|tbody|tr|th|td)\b|onclick|style|colspan~', $out, $mode);
            self::assertStringContainsString('H', $out);
            self::assertStringContainsString('c', $out);
        }
    }

    #[Test]
    public function textOfATableForTheBudgetAndTheEmptyCheck(): void
    {
        $s = $this->sanitizer();
        $html = '<table><thead><tr><th>Name</th><th>Price</th></tr></thead><tbody><tr><td>A</td><td>1</td></tr></tbody></table>';
        self::assertSame("Name Price \nA 1 ", $s->toText($html));
        self::assertFalse($s->isEmpty($html));
    }
}
