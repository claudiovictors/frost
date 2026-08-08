<?php

declare(strict_types=1);

namespace Frost\Ast\Stmt;

use Frost\Ast\Node;

final class ExpressionStmt implements Node
{
    public function __construct(
        public readonly Node $expr,
    ) {
    }
}