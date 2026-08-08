<?php

declare(strict_types=1);

namespace Frost\Expr;

use Frost\Ast\Node;

final class Literal implements Node
{
    public function __construct(
        public readonly LiteralKind $kind,
        public readonly string|int|float|bool|null $value,
    ) {
    }
}