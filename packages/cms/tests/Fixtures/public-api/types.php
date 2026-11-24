<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Fixture\PublicApi;

#[\AllowDynamicProperties]
class PublicType
{
}

/** @internal Fixture of a classified implementation interface. */
interface InternalType
{
}

/** The prose mentions @internal without applying the PHPDoc tag. */
trait UnclassifiedType
{
    /** @internal A member annotation does not classify its enclosing type. */
    public function helper(): void
    {
    }
}

/** @internal */
enum InternalEnum: string
{
    case Example = 'example';
}

$anonymous = new class {
    use UnclassifiedType;
};
$reference = PublicType::class;
