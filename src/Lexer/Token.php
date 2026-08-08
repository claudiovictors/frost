<?php

declare(strict_types=1);

namespace Frost\Lexer;

final class Token
{
    public function __construct(
        public readonly TokenKind $kind,
        public readonly string $value,
        public readonly int $line,
        public readonly int $column,
        public readonly int $offset,
    ) {
    }

    public function is(TokenKind $kind): bool
    {
        return $this->kind === $kind;
    }

    public function __toString(): string
    {
        $val = str_replace("\n", '\\n', $this->value);
        if (strlen($val) > 30) {
            $val = substr($val, 0, 27) . '...';
        }

        return sprintf('%s(%s) @%d:%d', $this->kind->name, $val, $this->line, $this->column);
    }
}