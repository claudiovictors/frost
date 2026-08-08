<?php

declare(strict_types=1);

namespace Frost\Expr;

use Frost\Ast\Node;

final class Await implements Node
{
    public function __construct(
        public readonly Node $expr,
    ) {
    }
}