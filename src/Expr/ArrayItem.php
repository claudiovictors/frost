<?php

declare(strict_types=1);

namespace Frost\Expr;

use Frost\Ast\Node;

final class ArrayItem implements Node
{
    /** @param Node|null $key null = item posicional (lista), não associativo */
    public function __construct(
        public readonly ?Node $key,
        public readonly Node $value,
    ) {
    }
}