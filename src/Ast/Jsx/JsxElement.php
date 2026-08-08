<?php

declare(strict_types=1);

namespace Frost\Ast\Jsx;

use Frost\Ast\Node;

final class JsxElement implements Node
{
    /**
     * @param JsxAttribute[] $attributes
     * @param Node[] $children JsxElement|JsxExpressionContainer|JsxText
     */
    public function __construct(
        public readonly string $tagName,
        public readonly array $attributes,
        public readonly array $children,
        public readonly bool $selfClosing,
    ) {
    }
}