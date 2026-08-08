<?php

declare(strict_types=1);

namespace Frost\Ast\Stmt;

use Frost\Ast\Node;

final class UseStmt implements Node
{
    /**
     * @param string[] $path segmentos do namespace, ex: ['App','Components','Header']
     *
     * Convenção v1 (ver débito no CLI): o ÚLTIMO segmento é o nome do
     * arquivo .php esperado (Header.php), procurado na mesma pasta 'source'
     * do frost.config.php — não existe resolução de namespace/PSR-4 de
     * verdade ainda, é so uma convenção de nome de arquivo.
     */
    public function __construct(
        public readonly array $path,
        public readonly ?string $alias,
    ) {
    }

    public function fileName(): string
    {
        return $this->path[count($this->path) - 1] ?? '';
    }

    public function localName(): string
    {
        return $this->alias ?? $this->fileName();
    }
}