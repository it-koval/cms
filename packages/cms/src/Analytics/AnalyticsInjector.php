<?php

declare(strict_types=1);

namespace Itk\Cms\Analytics;

/**
 * Puts the counters and verification tags of an AnalyticsConfig into an HTML page: the head tags before the first `</head>`, Metrika's noscript right after the opening `<body>`.
 * A counter the page already has, whether the layout writes it by hand or another listener added it, is not added twice, and neither is a verification tag (the same name with the same code).
 *
 * @internal
 */
final class AnalyticsInjector
{
    public static function inject(string $html, AnalyticsConfig $config): string
    {
        $head = self::head($html, $config);
        $body = self::noscript($html, $config);
        $headAt = \stripos($html, '</head>');
        $bodyAt = self::bodyEnd($html, false === $headAt ? 0 : $headAt);

        // the later position first, so the earlier one stays valid
        if ('' !== $body && null !== $bodyAt) {
            $html = \substr_replace($html, $body, $bodyAt, 0);
        }
        if ('' !== $head && false !== $headAt) {
            $html = \substr_replace($html, $head, $headAt, 0);
        }

        return $html;
    }

    private static function head(string $html, AnalyticsConfig $config): string
    {
        $out = '';
        // two accounts may verify one site, so the code of the owner and the one of the CMS can differ and both stay
        foreach (\array_unique(\array_filter([$config->yandexVerification, $config->webmasterVerification])) as $code) {
            $out .= self::verification($html, 'yandex-verification', $code);
        }
        if (null !== $config->googleVerification) {
            $out .= self::verification($html, 'google-site-verification', $config->googleVerification);
        }
        if (null !== $config->metrikaId && !self::hasMetrika($html, $config->metrikaId)) {
            $out .= self::metrika($config->metrikaId, $config->webvisor);
        }
        if (null !== $config->googleAnalyticsId && !self::hasGoogleAnalytics($html, $config->googleAnalyticsId)) {
            $out .= self::gtag($config->googleAnalyticsId);
        }

        return $out;
    }

    private static function verification(string $html, string $name, string $code): string
    {
        return self::hasMeta($html, $name, $code) ? '' : '<meta name="' . $name . '" content="' . self::attr($code) . '">' . "\n";
    }

    private static function noscript(string $html, AnalyticsConfig $config): string
    {
        if (null === $config->metrikaId || self::hasMetrika($html, $config->metrikaId)) {
            return '';
        }

        return "\n" . '<noscript><div><img src="https://mc.yandex.ru/watch/' . self::attr($config->metrikaId) . '" style="position:absolute; left:-9999px;" alt=""></div></noscript>';
    }

    private static function metrika(string $id, bool $webvisor): string
    {
        $js = \json_encode($id, \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT);
        $src = \json_encode('https://mc.yandex.ru/metrika/tag.js?id=' . $id, \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT);

        return '<script>' . "\n"
            . '(function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};m[i].l=1*new Date();' . "\n"
            . 'for(var j=0;j<document.scripts.length;j++){if(document.scripts[j].src===r){return;}}' . "\n"
            . 'k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})' . "\n"
            . '(window,document,"script",' . $src . ',"ym");' . "\n"
            . 'ym(' . $js . ',"init",{clickmap:true,trackLinks:true,accurateTrackBounce:true' . ($webvisor ? ',webvisor:true' : '') . '});' . "\n"
            . '</script>' . "\n";
    }

    private static function gtag(string $id): string
    {
        $js = \json_encode($id, \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT);

        return '<script async src="https://www.googletagmanager.com/gtag/js?id=' . \rawurlencode($id) . '"></script>' . "\n"
            . '<script>' . "\n"
            . 'window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}' . "\n"
            . "gtag('js',new Date());gtag('config'," . $js . ');' . "\n"
            . '</script>' . "\n";
    }

    /** Is there a `<meta name="…" content="…">` with this very name and code? A tag with the name and another code does not count: it verifies another account. */
    private static function hasMeta(string $html, string $name, string $content): bool
    {
        if (1 > \preg_match_all('/<meta\b[^>]*>/i', $html, $tags)) {
            return false;
        }
        foreach ($tags[0] as $tag) {
            if (\strtolower(self::attribute($tag, 'name') ?? '') === $name && self::attribute($tag, 'content') === $content) {
                return true;
            }
        }

        return false;
    }

    private static function attribute(string $tag, string $name): ?string
    {
        if (1 !== \preg_match('/(?<![\w-])' . $name . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+))/i', $tag, $m)) {
            return null;
        }

        return \html_entity_decode($m[1] ?: ($m[2] ?? '') ?: ($m[3] ?? ''), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

    /** The script of Metrika with this counter: tag.js?id=ID, or ym(ID, … written by hand, or the older watch.js with yaCounterID. */
    private static function hasMetrika(string $html, string $id): bool
    {
        if (1 !== \preg_match('~mc\.yandex\.ru/metrika/(?:tag|watch)\.js~i', $html)) {
            return false;
        }

        return 1 === \preg_match('~(?:tag\.js\?id=|\bym\(\s*|yaCounter)' . \preg_quote($id, '~') . '(?!\d)~', $html);
    }

    private static function hasGoogleAnalytics(string $html, string $id): bool
    {
        $quoted = \preg_quote($id, '~');

        return 1 === \preg_match('~googletagmanager\.com/gtag/js\?id=' . $quoted . '(?![A-Za-z0-9])~i', $html)
            || 1 === \preg_match('~gtag\(\s*[\'"]config[\'"]\s*,\s*[\'"]' . $quoted . '[\'"]~i', $html);
    }

    /** Where the first `<body …>` ends, looked for from $from on; null when the page has none. */
    private static function bodyEnd(string $html, int $from): ?int
    {
        if (1 !== \preg_match('/<body\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>/i', $html, $m, \PREG_OFFSET_CAPTURE, $from)) {
            return null;
        }

        return $m[0][1] + \strlen($m[0][0]);
    }

    private static function attr(string $value): string
    {
        return \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
