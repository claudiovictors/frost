<?php

declare(strict_types=1);

namespace Frost\Expr;

use Frost\Ast\Node;

final class ArrayLiteral implements Node
{
    /** @param ArrayItem[] $items */
    public function __construct(
        public readonly array $items,
    ) {
    }
}