<?php

declare(strict_types=1);

namespace Frost\Expr;

use Frost\Ast\Node;

final class Assign implements Node
{
    public function __construct(
        public readonly Node $target,
        public readonly Node $value,
    ) {
    }
}