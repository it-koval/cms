<?php

declare(strict_types=1);

namespace Itk\Cms\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL: JSON_EXTRACT(i.translations, '$.ru.draft.title') — MySQL JSON_EXTRACT (registered by the bundle; the path is usually a parameter).
 * Wrap in JSON_UNQUOTE() to compare as text.
 */
final class JsonExtract extends FunctionNode
{
    private Node $json;
    private Node $path;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->json = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->path = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'JSON_EXTRACT(' . $this->json->dispatch($sqlWalker) . ', ' . $this->path->dispatch($sqlWalker) . ')';
    }
}
