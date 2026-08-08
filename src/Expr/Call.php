<?php

declare(strict_types=1);

namespace Frost\Expr;

use Frost\Ast\Node;

final class Call implements Node
{
    /** @param Node[] $args */
    public function __construct(
        public readonly Node $callee,
        public readonly array $args,
    ) {
    }
}