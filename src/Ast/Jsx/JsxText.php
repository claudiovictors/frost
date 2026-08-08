<?php

declare(strict_types=1);

namespace Frost\Ast\Jsx;

use Frost\Ast\Node;

final class JsxText implements Node
{
    public function __construct(
        public readonly string $value,
    ) {
    }
}