<?php

declare(strict_types=1);

namespace Frost\Ast\Stmt;

use Frost\Ast\Node;

final class IfStmt implements Node
{
    /**
     * @param Node[] $thenBody
     * @param Node[]|null $elseBody null = sem else. Um `elseif` vira um único
     *   IfStmt dentro de $elseBody (ex: elseBody = [new IfStmt(...)]), então
     *   o Transpiler não precisa saber a diferença entre elseif e
     *   `else { if (...) }` — são a mesma coisa em JS de qualquer forma.
     */
    public function __construct(
        public readonly Node $cond,
        public readonly array $thenBody,
        public readonly ?array $elseBody,
    ) {
    }
}