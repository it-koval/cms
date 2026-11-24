<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Itk\Cms\Entity\Request;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Schema\Schema;

/**
 * The text of a request for a messenger chat (Telegram and MAX read the same): the template of the form (FormDef::telegram(), used for every messenger),
 * else the title and one «label: value» line per field. Plain lines with escaped values, so both messengers take it as HTML.
 * In the current language (the senders run it in admin_locale: a chat is read by many).
 *
 * @internal
 */
final class RequestText
{
    /** @param \Closure(?string): string $escape escapes a value for the HTML of the messenger */
    public static function build(Schema $schema, Request $r, bool $resend, \Closure $escape): string
    {
        $form = $schema->form($r->form);
        $e = static fn (mixed $v): string => $escape(\is_scalar($v) ? (string) $v : '');
        $title = $form?->formTitle() ?? $r->form;
        $template = $form?->telegramTemplate();

        if (null !== $template) {
            $vars = ['id' => (string) $r->id, 'form' => $e($title), 'phone' => $e($r->phone), 'name' => $e($r->name), 'city' => $e($r->city), 'url' => $e($r->sourceUrl)];
            foreach ($r->data as $k => $v) {
                $vars[(string) $k] = $e($v);
            }
            $lines = [(string) \preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => $vars[$m[1]] ?? '', $template)];
        } else {
            $lines = [I18n::t('http.telegram_title', ['id' => $r->id, 'form' => $e($title)])];
            $shown = [];
            foreach ($form?->fieldList() ?? [] as $f) {
                $v = $r->data[$f->key()] ?? null;
                if (null === $v || '' === $v) {
                    continue;
                }
                $shown[$f->key()] = true;
                $lines[] = $e($f->label()) . ': ' . $e(\is_bool($v) ? I18n::t($v ? 'http.yes' : 'http.no') : $v);
            }
            foreach ($r->data as $k => $v) {
                if (!isset($shown[$k]) && \is_scalar($v) && '' !== $v) {
                    $lines[] = $e($k) . ': ' . $e($v);
                }
            }
            if (null !== $r->sourceUrl && '' !== $r->sourceUrl) {
                $lines[] = I18n::t('http.request_page_line', ['url' => $e($r->sourceUrl)]);
            }
        }
        if ($resend) {
            \array_unshift($lines, I18n::t('http.telegram_resend', ['id' => $r->id]), '');
        }

        return \implode("\n", $lines);
    }
}
