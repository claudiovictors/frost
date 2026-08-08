<?php

declare(strict_types=1);

namespace Frost\Parser;

use Frost\Expr\ArrayDim;
use Frost\Expr\ArrowFunction;
use Frost\Expr\Assign;
use Frost\Expr\Binary;
use Frost\Expr\Call;
use Frost\Expr\Identifier;
use Frost\Expr\Literal;
use Frost\Expr\MemberAccess;
use Frost\Expr\Ternary;
use Frost\Expr\Unary;
use Frost\Expr\Variable;
use Frost\Ast\Jsx\JsxElement;
use Frost\Ast\Jsx\JsxExpressionContainer;
use Frost\Ast\Jsx\JsxText;
use Frost\Ast\Node;
use Frost\Ast\Stmt\ExpressionStmt;
use Frost\Ast\Stmt\FunctionDecl;
use Frost\Ast\Stmt\ReturnStmt;

final class AstDumper
{
    public static function dump(Node $node, int $depth = 0): string
    {
        $pad = str_repeat('  ', $depth);

        return match (true) {
            $node instanceof FunctionDecl => "{$pad}FunctionDecl({$node->name}, params=" . implode(',', array_map(fn ($p) => '$' . $p->name, $node->params)) . ")\n"
                . implode('', array_map(fn ($s) => self::dump($s, $depth + 1), $node->body)),
            $node instanceof ReturnStmt => "{$pad}Return\n" . ($node->expr ? self::dump($node->expr, $depth + 1) : ''),
            $node instanceof ExpressionStmt => "{$pad}ExpressionStmt\n" . self::dump($node->expr, $depth + 1),
            $node instanceof Assign => "{$pad}Assign\n" . self::dump($node->target, $depth + 1) . self::dump($node->value, $depth + 1),
            $node instanceof Binary => "{$pad}Binary({$node->op})\n" . self::dump($node->left, $depth + 1) . self::dump($node->right, $depth + 1),
            $node instanceof Unary => "{$pad}Unary({$node->op})\n" . self::dump($node->expr, $depth + 1),
            $node instanceof Ternary => "{$pad}Ternary\n" . self::dump($node->cond, $depth + 1)
                . ($node->then ? self::dump($node->then, $depth + 1) : "{$pad}  (curto)\n")
                . self::dump($node->else, $depth + 1),
            $node instanceof Call => "{$pad}Call\n" . self::dump($node->callee, $depth + 1)
                . implode('', array_map(fn ($a) => self::dump($a, $depth + 1), $node->args)),
            $node instanceof MemberAccess => "{$pad}MemberAccess(" . ($node->isStatic ? '::' : '->') . "{$node->member})\n" . self::dump($node->object, $depth + 1),
            $node instanceof ArrayDim => "{$pad}ArrayDim\n" . self::dump($node->array, $depth + 1) . ($node->dim ? self::dump($node->dim, $depth + 1) : ''),
            $node instanceof ArrowFunction => "{$pad}ArrowFunction(params=" . implode(',', array_map(fn ($p) => '$' . $p->name, $node->params)) . ")\n" . self::dump($node->body, $depth + 1),
            $node instanceof Variable => "{$pad}Variable(\${$node->name})\n",
            $node instanceof Identifier => "{$pad}Identifier({$node->name})\n",
            $node instanceof Literal => "{$pad}Literal({$node->kind->name}, " . var_export($node->value, true) . ")\n",
            $node instanceof JsxElement => "{$pad}JsxElement<{$node->tagName}>" . ($node->selfClosing ? ' /' : '') . "\n"
                . implode('', array_map(fn ($a) => "{$pad}  @attr {$a->name}" . ($a->value ? "=\n" . self::dump($a->value, $depth + 2) : " (bool)\n"), $node->attributes))
                . implode('', array_map(fn ($c) => self::dump($c, $depth + 1), $node->children)),
            $node instanceof JsxExpressionContainer => "{$pad}JsxExpr\n" . self::dump($node->expr, $depth + 1),
            $node instanceof JsxText => "{$pad}JsxText(" . trim($node->value) . ")\n",
            default => "{$pad}" . $node::class . "\n",
        };
    }
}