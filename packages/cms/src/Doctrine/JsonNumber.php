<?php

declare(strict_types=1);

namespace Itk\Cms\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/** DQL: JSON_NUMBER(i.translations, '{"ru","draft","seats"}') — the value at a JsonPath as a number, a JSON number or a numeric string alike, NULL otherwise: to sort and compare numbers (registered by the bundle). */
final class JsonNumber extends FunctionNode
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
        $text = '(' . $this->json->dispatch($sqlWalker) . ' #>> CAST(' . $this->path->dispatch($sqlWalker) . ' AS text[]))';

        return "(CASE WHEN $text ~ '^-?[0-9]+(\\.[0-9]+)?([eE][+-]?[0-9]+)?$' THEN CAST($text AS NUMERIC) END)";
    }
}
