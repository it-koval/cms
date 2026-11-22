<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

/**
 * A value of a form as plain text. Not strip_tags(): it takes any "<" without a space after it for a tag and drops the text up to the next ">" or to the end ("<500", "<XS").
 * Here a tag is the lowercase name of an HTML element with nothing but name=value attributes. Any other "<" is text and gets a space after it, so that no value holds
 * the start of a tag for whoever prints it or strips tags once more.
 *
 * @internal
 */
final class PlainText
{
    private const ELEMENTS = 'a|abbr|address|area|article|aside|audio|b|base|bdi|bdo|big|blockquote|body|br|button|canvas|caption|center|cite|code|col|colgroup'
        . '|data|datalist|dd|del|details|dfn|dialog|div|dl|dt|em|embed|fieldset|figcaption|figure|font|footer|form|h[1-6]|head|header|hr|html'
        . '|i|iframe|img|input|ins|kbd|label|legend|li|link|main|map|mark|menu|meta|meter|nav|nobr|noscript|object|ol|optgroup|option|output'
        . '|p|param|picture|pre|progress|q|rp|rt|ruby|s|samp|script|search|section|select|slot|small|source|span|strike|strong|style|sub|summary|sup|svg'
        . '|table|tbody|td|template|textarea|tfoot|th|thead|time|title|tr|track|tt|u|ul|var|video|wbr';

    /** Possessive, and a quoted value ends at a "<": linear on any input. */
    private const TAG = '~</(?:' . self::ELEMENTS . ')\s*+>'
        . '|<(?:' . self::ELEMENTS . ')(?:\s++[a-zA-Z][\w:.-]*+\s*+=\s*+(?:"[^"<]*+"|\'[^\'<]*+\'|[^\s"\'=<>`]++))*+\s*+/?>~';

    /** A "<" with no ASCII white space after it, what strip_tags() takes for the start of a tag. */
    private const BRACKET = '~<(?![ \t\n\x0B\f\r])~';

    public static function from(string $raw): string
    {
        $text = \str_replace("\0", '', $raw); // first: it must not hide a tag, and PostgreSQL takes no null byte in jsonb
        $text = \preg_replace(self::TAG, '', $text) ?? $text; // when PCRE gives up, the tags stay as text

        return (string) \preg_replace(self::BRACKET, '< ', $text);
    }
}
