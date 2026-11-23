<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp;

use Itk\Cms\Content\ContentException;
use Itk\Cms\I18n\ContentLocales;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * The common part of the tools of the core: schema builders and argument helpers. A tool of a site may extend it too.
 * There is no default for annotations(): a tool says itself whether it reads, writes or destroys, because a client asks the person (or does not) by this hint.
 */
abstract class AbstractMcpTool implements McpToolInterface
{
    private ?ContentLocales $contentLocales = null;

    #[Required]
    public function setContentLocales(ContentLocales $contentLocales): void
    {
        $this->contentLocales = $contentLocales;
    }

    /** Says nothing, so McpToolRegistry refuses the tool with its name. An abstract method would stop the whole site with a fatal error when a tool written before the rule is loaded. */
    public function annotations(): array
    {
        return [];
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     *
     * @return array<string, mixed>
     */
    protected static function schema(array $properties, array $required = []): array
    {
        $s = ['type' => 'object', 'properties' => [] === $properties ? new \stdClass() : $properties, 'additionalProperties' => false];
        if ([] !== $required) {
            $s['required'] = $required;
        }

        return $s;
    }

    /** @return array<string, mixed> */
    protected static function string(string $description, ?int $max = 500): array
    {
        return ['type' => 'string', 'description' => $description] + (null !== $max ? ['maxLength' => $max] : []);
    }

    /** @return array<string, mixed> */
    protected static function localeProperty(): array
    {
        return ['type' => 'string', 'description' => 'Content language code (e.g. "ru", "en"). Default: the main language of the site.', 'maxLength' => 12];
    }

    /** @return array<string, mixed> */
    protected static function limitProperty(int $default, int $max): array
    {
        return ['type' => 'integer', 'description' => \sprintf('How many items to return (default %d, at most %d).', $default, $max), 'minimum' => 1, 'maximum' => $max];
    }

    /** @return array<string, mixed> */
    protected static function offsetProperty(): array
    {
        return ['type' => 'integer', 'description' => 'How many items to skip (for the next page).', 'minimum' => 0];
    }

    /** @param array<string, mixed> $args */
    protected function locale(array $args): ?string
    {
        $l = $args['locale'] ?? null;
        if (null === $l || '' === $l) {
            return null;
        }
        if (!\is_string($l)) {
            throw new McpToolException('locale must be a language code like "ru" or "en"');
        }
        if (null === $this->contentLocales) {
            if (1 !== \preg_match('/^[A-Za-z]{2,8}(-[A-Za-z0-9]{1,8})?$/', $l)) {
                throw new McpToolException('locale must be a language code like "ru" or "en"');
            }

            return $l;
        }

        try {
            return $this->contentLocales->assertUsable($l);
        } catch (ContentException) {
            throw new McpToolException('locale must be a language code like "ru" or "en"');
        }
    }

    /** @param array<string, mixed> $args */
    protected static function limit(array $args, int $default, int $max): int
    {
        return \max(1, \min($max, (int) ($args['limit'] ?? $default)));
    }

    /** @param array<string, mixed> $args */
    protected static function offset(array $args): int
    {
        return \max(0, (int) ($args['offset'] ?? 0));
    }

    /** @param array<string, mixed> $args */
    protected static function bool(array $args, string $key, bool $default): bool
    {
        return \array_key_exists($key, $args) ? (bool) $args[$key] : $default;
    }
}
