<?php

declare(strict_types=1);

namespace Frost\Ast\Stmt;

use Frost\Ast\Node;

final class TryStmt implements Node
{
    /**
     * @param Node[] $tryBody
     * @param string|null $catchVar null = sem catch (só try/finally, incomum mas válido)
     * @param Node[]|null $catchBody
     * @param Node[]|null $finallyBody
     */
    public function __construct(
        public readonly array $tryBody,
        public readonly ?string $catchVar,
        public readonly ?array $catchBody,
        public readonly ?array $finallyBody,
    ) {
    }
}