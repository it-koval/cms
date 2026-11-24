<?php

declare(strict_types=1);

namespace Itk\Cms\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/** The first value in a JSON object, in the same order it is read from jsonb. */
final class JsonFirstValue extends FunctionNode
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
        return 'jsonb_path_query_first(' . $this->json->dispatch($sqlWalker) . ", '$.*')";
    }
}
