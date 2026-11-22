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
        . '|data|datalist|dd|del|details|dfn|dialog|div|dl|dt|em|embed|fieldset|figcaption|figure|font|footer|form|h[1-6]|head|header|hgroup|hr|html'
        . '|i|iframe|img|input|ins|kbd|label|legend|li|link|main|map|mark|math|menu|meta|meter|nav|nobr|noscript|object|ol|optgroup|option|output'
        . '|p|param|picture|pre|progress|q|rp|rt|ruby|s|samp|script|search|section|select|slot|small|source|span|strike|strong|style|sub|summary|sup|svg'
        . '|table|tbody|td|template|textarea|tfoot|th|thead|time|title|tr|track|tt|u|ul|var|video|wbr';

    /** Possessive, and a quoted value ends at a "<": linear on any input. The first look ahead spares the list of elements for a "<" that starts no tag (PCRE without JIT). */
    private const TAG = '~<(?=/?[a-z][a-z0-9]*+[\s/>])(?:/(?>(?:' . self::ELEMENTS . ')(?=[\s/>]))\s*+>'
        . '|(?>(?:' . self::ELEMENTS . ')(?=[\s/>]))(?:\s++[a-zA-Z][\w:.-]*+\s*+=\s*+(?:"[^"<]*+"|\'[^\'<]*+\'|[^\s"\'=<>`]++))*+\s*+/?>)~';

    /** A "<" followed by anything but ASCII white space, what strip_tags() takes for the start of a tag. The last character of a value may be a "<". */
    private const BRACKET = '~<(?=[^ \t\n\x0B\f\r])~';

    /** @param int $keep how many characters to keep; the caller refuses a longer value */
    public static function from(string $raw, int $keep = \PHP_INT_MAX): string
    {
        $text = \str_replace("\0", '', $raw); // first: it must not hide a tag, and PostgreSQL takes no null byte in jsonb
        $text = \preg_replace(self::TAG, '', $text) ?? $text; // when PCRE gives up, the tags stay as text
        $text = \mb_substr(\trim($text), 0, $keep); // before the spaces: megabytes of "<" would double

        return \mb_substr((string) \preg_replace(self::BRACKET, '< ', $text), 0, $keep); // no second trim: a space at the cut counts for the length
    }
}
