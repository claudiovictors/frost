<?php

declare(strict_types=1);

namespace Frost\Expr;

use Frost\Ast\Node;

final class Ternary implements Node
{
    /** @param Node|null $then null indica a forma curta `$cond ?: $else` */
    public function __construct(
        public readonly Node $cond,
        public readonly ?Node $then,
        public readonly Node $else,
    ) {
    }
}