<?php

declare(strict_types=1);

namespace Frost\Ast\Stmt;

use Frost\Ast\Node;
use Frost\Ast\Param;

final class FunctionDecl implements Node
{
    /**
     * @param Param[] $params
     * @param Node[] $body lista de statements
     */
    public function __construct(
        public readonly string $name,
        public readonly array $params,
        public readonly array $body,
        public readonly bool $isAsync = false,
    ) {
    }
}