<?php

declare(strict_types=1);

namespace Itk\Cms\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/** DQL: JSON_UNQUOTE(JSON_EXTRACT(i.translations, :path)) — the JSON value as plain text (registered by the bundle). */
final class JsonUnquote extends FunctionNode
{
    private Node $json;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->json = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'JSON_UNQUOTE(' . $this->json->dispatch($sqlWalker) . ')';
    }
}
