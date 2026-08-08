<?php

declare(strict_types=1);

namespace Frost\Ast\Jsx;

use Frost\Ast\Node;

final class JsxExpressionContainer implements Node
{
    public function __construct(
        public readonly Node $expr,
    ) {
    }
}