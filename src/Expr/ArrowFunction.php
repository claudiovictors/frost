<?php

declare(strict_types=1);

namespace Frost\Expr;

use Frost\Ast\Node;
use Frost\Ast\Param;

final class ArrowFunction implements Node
{
    /** @param Param[] $params */
    public function __construct(
        public readonly array $params,
        public readonly Node $body,
        public readonly bool $isAsync = false,
    ) {
    }
}