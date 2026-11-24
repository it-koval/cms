<?php

declare(strict_types=1);

namespace Itk\Cms\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * ITK_PHONE_DIGITS(r.phone) returns the ASCII digits of a stored phone number for substring searches.
 *
 * @internal
 */
final class PhoneDigits extends FunctionNode
{
    private Node $phone;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->phone = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'regexp_replace(' . $this->phone->dispatch($sqlWalker) . ", '[^0-9]', '', 'g')";
    }
}
