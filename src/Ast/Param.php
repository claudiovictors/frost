<?php

declare(strict_types=1);

namespace Frost\Ast;

final class Param implements Node
{
    public function __construct(
        public readonly string $name,
        public readonly ?Node $default = null,
    ) {
    }
}