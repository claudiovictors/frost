<?php

declare(strict_types=1);

namespace Frost\Expr;

enum LiteralKind
{
    case STRING;
    case INT;
    case FLOAT;
    case BOOL;
    case NULL;
}