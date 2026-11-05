<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Model;

/**
 * Public API: the search cannot answer now (the knowledge base is off, the embedder failed for the query). A site search shows "search is unavailable",
 * an MCP tool returns an error. The message is for logs and the developer, never for visitors.
 */
final class KnowledgeBaseUnavailable extends \RuntimeException
{
}
