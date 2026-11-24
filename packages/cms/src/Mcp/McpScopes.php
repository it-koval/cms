<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp;

use Itk\Cms\Content\ContentException;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Security\RoleResolver;

/**
 * What a key of an AI assistant may be used for. A scope only ever NARROWS the rights of the person the key belongs to:
 * a tool needs its scope AND the person's own right to the thing it touches.
 *
 *   read      look at pages, records, media, menus, settings, SEO, history (default)
 *   write     change DRAFTS (nothing goes live); needs read
 *   publish   put drafts on the site and take pages off it (page_unpublish: the live page disappears at once); needs write
 *   requests  read and handle requests from the site forms: names and phone numbers of customers (default OFF)
 *
 * «requests» cannot share a key with «write» or «publish»: a visitor of the site can write anything into a form, and a key that reads those texts and also changes or publishes pages
 * could be talked into it by one of them. The rule is checked when a key is MADE (conflict()); keys issued before it keep their scopes and keep working.
 */
final class McpScopes
{
    public const READ = 'read';
    public const WRITE = 'write';
    public const PUBLISH = 'publish';
    public const REQUESTS = 'requests';
    public const ALL = [self::READ, self::WRITE, self::PUBLISH, self::REQUESTS];
    public const DEFAULT = [self::READ];
    /** Scopes that may not be in one key: scope => the scopes that cannot go with it. */
    private const EXCLUDES = [
        self::WRITE => [self::REQUESTS],
        self::PUBLISH => [self::REQUESTS],
        self::REQUESTS => [self::WRITE, self::PUBLISH],
    ];

    /**
     * Validates scopes and adds what each needs (publish -> write -> read), in the fixed order.
     *
     * @param list<mixed> $scopes
     *
     * @return list<string>
     */
    public static function normalize(array $scopes): array
    {
        foreach ($scopes as $scope) {
            if (!\in_array($scope, self::ALL, true)) {
                throw ContentException::invalid(I18n::t('http.token_scope_unknown'), ['scopes' => I18n::t('http.token_scope_choose')]);
            }
        }
        $have = \array_flip($scopes);
        if (isset($have[self::PUBLISH])) {
            $have[self::WRITE] = true;
        }
        if (isset($have[self::WRITE])) {
            $have[self::READ] = true;
        }

        return \array_values(\array_filter(self::ALL, static fn (string $s): bool => isset($have[$s])));
    }

    /**
     * What stops these scopes from being one key, in words for the person who makes it; null when they go together.
     *
     * @param list<string> $scopes normalized
     */
    public static function conflict(array $scopes): ?string
    {
        $labels = \array_column(self::catalog(), 'label', 'key');
        foreach ($scopes as $scope) {
            $clash = \array_values(\array_intersect(self::EXCLUDES[$scope] ?? [], $scopes));
            if ([] !== $clash) {
                return I18n::t('ops.mcp_scope_conflict', ['scope' => I18n::t('ops.quoted', ['text' => $labels[$scope]]), 'others' => \implode(', ', \array_map(static fn (string $c): string => I18n::t('ops.quoted', ['text' => $labels[$c]]), $clash))]);
            }
        }

        return null;
    }

    /**
     * What the admin offers: { key, label, hint, default, warning?, excludes? }.
     *
     * @return list<array<string, mixed>>
     */
    public static function catalog(): array
    {
        return [
            ['key' => self::READ, 'label' => I18n::t('ops.mcp_scope_read_label'), 'hint' => I18n::t('ops.mcp_scope_read_hint'), 'default' => true],
            ['key' => self::WRITE, 'label' => I18n::t('ops.mcp_scope_write_label'), 'hint' => I18n::t('ops.mcp_scope_write_hint'), 'default' => false, 'excludes' => self::EXCLUDES[self::WRITE]],
            ['key' => self::PUBLISH, 'label' => I18n::t('ops.mcp_scope_publish_label'), 'hint' => I18n::t('ops.mcp_scope_publish_hint'), 'default' => false, 'excludes' => self::EXCLUDES[self::PUBLISH]],
            ['key' => self::REQUESTS, 'label' => I18n::t('ops.mcp_scope_requests_label'), 'hint' => I18n::t('ops.mcp_scope_requests_hint'), 'default' => false, 'excludes' => self::EXCLUDES[self::REQUESTS], 'warning' => I18n::t('ops.mcp_scope_requests_warning')],
        ];
    }

    /**
     * The scopes that mean something for this person: no point in «Публиковать» for somebody who cannot publish anything.
     *
     * @param array{perms: array<string, list<string>>, templates: list<string>|null} $rights
     *
     * @return list<string>
     */
    public static function offeredTo(array $rights): array
    {
        $content = static function (string $action) use ($rights): bool {
            foreach ($rights['perms'] as $area => $actions) {
                if (('pages' === $area || \str_starts_with($area, 'col:')) && \in_array($action, $actions, true)) {
                    return true;
                }
            }

            return false;
        };
        $out = [self::READ];
        if ($content('edit')) {
            $out[] = self::WRITE;
        }
        if ($content('publish')) {
            $out[] = self::PUBLISH;
        }
        if (RoleResolver::allows($rights, 'requests', 'view')) {
            $out[] = self::REQUESTS;
        }

        return $out;
    }
}
