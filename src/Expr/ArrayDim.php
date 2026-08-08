<?php

declare(strict_types=1);

namespace Frost\Expr;

use Frost\Ast\Node;

final class ArrayDim implements Node
{
    public function __construct(
        public readonly Node $array,
        public readonly ?Node $dim,
    ) {
    }
}