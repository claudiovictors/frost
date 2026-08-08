<?php

declare(strict_types=1);

namespace Frost\Ast\Stmt;

use Frost\Ast\Node;

final class ForeachStmt implements Node
{
    /**
     * @param string|null $keyVar null = forma sem chave: `foreach ($x as $v)`
     * @param Node[] $body
     */
    public function __construct(
        public readonly Node $iterable,
        public readonly ?string $keyVar,
        public readonly string $valueVar,
        public readonly array $body,
    ) {
    }
}