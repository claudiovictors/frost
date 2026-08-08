<?php

declare(strict_types=1);

namespace Frost\Expr;

use Frost\Ast\Node;

final class Unary implements Node
{
    public function __construct(
        public readonly string $op,
        public readonly Node $expr,
    ) {
    }
}