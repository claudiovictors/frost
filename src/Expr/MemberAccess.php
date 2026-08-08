<?php

declare(strict_types=1);

namespace Frost\Expr;

use Frost\Ast\Node;

final class MemberAccess implements Node
{
    public function __construct(
        public readonly Node $object,
        public readonly string $member,
        public readonly bool $isStatic,
    ) {
    }
}