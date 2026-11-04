<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Content\FieldAccess;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\McpToolException;
use Itk\Cms\Mcp\Support\Format;
use Itk\Cms\Schema\Schema;

/** @internal */
final class SettingsGetTool extends AbstractMcpTool
{
    /** Settings that look like secrets are never handed out, whatever group they are in. */
    private const SECRET = '/(secret|token|password|passwd|pwd|apikey|api_key|dsn|private|credential)/i';

    public function __construct(
        private readonly Schema $schema,
        private readonly SettingsService $settings,
        private readonly FieldAccess $fields,
    ) {
    }

    public function name(): string
    {
        return 'settings_get';
    }

    public function description(): string
    {
        return 'Read the site settings (site name, contacts, social links, texts) of the groups the key owner may view. Passwords, tokens, mail server, Telegram chats and e-mail addresses of notifications are never included. Read-only.';
    }

    public function annotations(): array
    {
        return ['readOnly' => true, 'destructive' => false, 'idempotent' => true];
    }

    public function inputSchema(): array
    {
        return self::schema([
            'group' => self::string('Key of one settings group (from site_schema); omit for all you may see.', 64),
            'locale' => self::localeProperty(),
        ]);
    }

    public function requiredScope(): string
    {
        return McpScopes::READ;
    }

    public function call(array $args, McpContext $context): array
    {
        $all = $this->settings->all(self::locale($args));
        $groups = [];
        foreach ($this->schema->settingsGroups() as $g) {
            if (isset($args['group']) && $g->key() !== $args['group']) {
                continue;
            }
            if (!$context->can('set:' . $g->key(), 'view')) {
                continue;
            }
            $values = [];
            foreach ($this->fields->visible($g->fieldList()) as $f) {
                if ($f->isNote() || 1 === \preg_match(self::SECRET, $f->key() . ' ' . $f->label())) {
                    continue;
                }
                $values[$f->key()] = ['label' => $f->label(), 'value' => Format::plain($all[$f->key()] ?? null)];
            }
            $groups[] = ['key' => $g->key(), 'title' => $g->groupTitle(), 'settings' => $values ?: new \stdClass()];
        }
        if (isset($args['group']) && [] === $groups) {
            throw new McpToolException('No such settings group (or you may not see it): ' . $args['group']);
        }

        return ['groups' => $groups];
    }
}
