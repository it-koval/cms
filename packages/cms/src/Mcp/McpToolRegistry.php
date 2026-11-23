<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp;

use Itk\Cms\Extension\Registry;
use Itk\Cms\I18n\ContentLocales;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * All tools of the site: the core's, the services tagged `itk.mcp_tool`, and the classes named in Registry::mcpTools().
 * The list is checked when it is built, so a mistake of a site shows at the first call and not as a tool that quietly does something else:
 * two classes with one tool name (a site cannot replace a tool of the core), a scope that does not exist, hints (annotations) that are not what the interface says.
 *
 * @internal
 */
final class McpToolRegistry
{
    /** @var array<string, McpToolInterface>|null */
    private ?array $all = null;

    /** @param iterable<McpToolInterface> $tools */
    public function __construct(
        #[AutowireIterator('itk.mcp_tool')]
        private readonly iterable $tools,
        private readonly Registry $extensions,
        private readonly ?ContentLocales $contentLocales = null,
    ) {
    }

    /** @return array<string, McpToolInterface> by name */
    public function all(): array
    {
        if (null !== $this->all) {
            return $this->all;
        }
        $list = [];
        foreach ($this->tools as $tool) {
            $this->add($list, $tool);
        }
        foreach ($this->extensions->mcpToolClasses() as $class) {
            if (\class_exists($class) && \is_subclass_of($class, McpToolInterface::class)) {
                try {
                    $tool = new $class();
                } catch (\Throwable) {
                    continue; // a tool that needs arguments must be a service: it is already in the list above
                }
                if ($tool instanceof AbstractMcpTool && null !== $this->contentLocales) {
                    $tool->setContentLocales($this->contentLocales);
                }
                $this->add($list, $tool);
            }
        }
        \ksort($list);

        return $this->all = $list;
    }

    /** @param array<string, McpToolInterface> $list */
    private function add(array &$list, McpToolInterface $tool): void
    {
        $name = $tool->name();
        if (isset($list[$name])) {
            if ($list[$name]::class === $tool::class) {
                return; // one class that is a service and is also named by an extension
            }

            throw new McpToolListError(\sprintf('The MCP tool name "%s" is used by two classes: %s and %s. A name must be unique, and a tool of a site cannot replace a tool of the core: rename one of them.', $name, $list[$name]::class, $tool::class));
        }
        if (!\in_array($tool->requiredScope(), McpScopes::ALL, true)) {
            throw new McpToolListError(\sprintf('The MCP tool "%s" (%s) asks for the scope "%s", which does not exist. Known scopes: %s.', $name, $tool::class, $tool->requiredScope(), \implode(', ', McpScopes::ALL)));
        }
        $hints = $tool->annotations();
        if (!\is_bool($hints['readOnly'] ?? null) || !\is_bool($hints['destructive'] ?? null) || (isset($hints['idempotent']) && !\is_bool($hints['idempotent']))) {
            throw new McpToolListError(\sprintf('The MCP tool "%s" (%s) must say in annotations() what it does: [\'readOnly\' => bool, \'destructive\' => bool, \'idempotent\' => bool (optional)].', $name, $tool::class));
        }
        $list[$name] = $tool;
    }

    public function get(string $name): ?McpToolInterface
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * The tools this key may see: its scopes, and for conditional tools the person's rights.
     *
     * @return array<string, McpToolInterface>
     */
    public function visibleTo(McpContext $context): array
    {
        return \array_filter($this->all(), static fn (McpToolInterface $t): bool => $context->hasScope($t->requiredScope()) && (!$t instanceof McpConditionalTool || $t->isAvailableTo($context)));
    }
}
