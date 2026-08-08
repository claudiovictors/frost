<?php

declare(strict_types=1);

namespace Frost\Expr;

use Frost\Ast\Node;

final class Binary implements Node
{
    /** @param string $op ex: '+', '-', '==', '&&', '??' etc. */
    public function __construct(
        public readonly string $op,
        public readonly Node $left,
        public readonly Node $right,
    ) {
    }
}