<?php

declare(strict_types=1);

namespace Frost\Ast\Jsx;

use Frost\Ast\Node;

final class JsxAttribute implements Node
{
    /** @param Node|null $value null = atributo booleano (ex: <Checkbox checked />) */
    public function __construct(
        public readonly string $name,
        public readonly ?Node $value,
    ) {
    }
}