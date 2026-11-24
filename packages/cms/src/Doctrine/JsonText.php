<?php

declare(strict_types=1);

namespace Itk\Cms\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL: JSON_TEXT(i.translations, '{"ru","draft","title"}') — the value at a JsonPath as plain text, NULL when it is missing or null (jsonb #>>; registered by the bundle).
 *
 * @internal
 */
final class JsonText extends FunctionNode
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
        return '(' . $this->json->dispatch($sqlWalker) . ' #>> CAST(' . $this->path->dispatch($sqlWalker) . ' AS text[]))';
    }
}
