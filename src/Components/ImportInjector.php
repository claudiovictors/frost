<?php

declare(strict_types=1);

namespace Frost\Components;

use Frost\Expr\ArrayDim;
use Frost\Expr\ArrayItem;
use Frost\Expr\ArrayLiteral;
use Frost\Expr\ArrowFunction;
use Frost\Expr\Assign;
use Frost\Expr\Await;
use Frost\Expr\Binary;
use Frost\Expr\Call;
use Frost\Expr\Identifier;
use Frost\Expr\MemberAccess;
use Frost\Expr\Ternary;
use Frost\Expr\Unary;
use Frost\Ast\Jsx\JsxElement;
use Frost\Ast\Jsx\JsxExpressionContainer;
use Frost\Ast\Node;
use Frost\Ast\Stmt\ExpressionStmt;
use Frost\Ast\Stmt\ForeachStmt;
use Frost\Ast\Stmt\FunctionDecl;
use Frost\Ast\Stmt\IfStmt;
use Frost\Ast\Stmt\ReturnStmt;
use Frost\Ast\Stmt\TryStmt;
use Frost\Ast\Stmt\UseStmt;

/**
 * Percorre a AST inteira coletando: (a) tags JSX usadas que batem com o
 * ComponentRegistry, (b) identificadores chamados como função que batem com
 * o HookRegistry. Com isso, gera as linhas `import { ... } from '...'` que
 * o dev não precisa escrever à mão.
 *
 * Só cobre os nós de AST que existem hoje no Parser (ver débito do pacote
 * Parser) — se o Parser ganhar novos tipos de nó no futuro, este walker
 * precisa ser estendido junto, senão passa a ignorar silenciosamente
 * sub-árvores novas.
 */
final class ImportInjector
{
    /** @param Node[] $programStmts */
    public static function generateImports(array $programStmts): string
    {
        $tags = [];
        $hooks = [];
        foreach ($programStmts as $stmt) {
            self::collect($stmt, $tags, $hooks);
        }

        $reactHooks = array_values(array_unique(array_filter(
            $hooks,
            static fn (string $h) => HookRegistry::isHook($h),
        )));
        $rnComponents = array_values(array_unique(array_filter(
            $tags,
            static fn (string $t) => ComponentRegistry::sourceFor($t) === 'react-native',
        )));

        $out = '';
        if ($reactHooks !== []) {
            sort($reactHooks);
            $out .= "import { " . implode(', ', $reactHooks) . " } from 'react';\n";
        }
        if ($rnComponents !== []) {
            sort($rnComponents);
            $out .= "import { " . implode(', ', $rnComponents) . " } from 'react-native';\n";
        }
        if ($out !== '') {
            $out .= "\n";
        }

        return $out;
    }

    /**
     * @param string[] $tags
     * @param string[] $hooks
     */
    private static function collect(Node $node, array &$tags, array &$hooks): void
    {
        if ($node instanceof FunctionDecl) {
            foreach ($node->body as $s) {
                self::collect($s, $tags, $hooks);
            }

            return;
        }
        if ($node instanceof ReturnStmt) {
            if ($node->expr !== null) {
                self::collect($node->expr, $tags, $hooks);
            }

            return;
        }
        if ($node instanceof ExpressionStmt) {
            self::collect($node->expr, $tags, $hooks);

            return;
        }
        if ($node instanceof IfStmt) {
            self::collect($node->cond, $tags, $hooks);
            foreach ($node->thenBody as $s) {
                self::collect($s, $tags, $hooks);
            }
            foreach ($node->elseBody ?? [] as $s) {
                self::collect($s, $tags, $hooks);
            }

            return;
        }
        if ($node instanceof ForeachStmt) {
            self::collect($node->iterable, $tags, $hooks);
            foreach ($node->body as $s) {
                self::collect($s, $tags, $hooks);
            }

            return;
        }
        if ($node instanceof UseStmt) {
            // componente próprio, importado via 'use' — não é RN nem hook,
            // o import dele é resolvido pelo CLI (Compiler::compileTree), não aqui.
            return;
        }
        if ($node instanceof TryStmt) {
            foreach ($node->tryBody as $s) {
                self::collect($s, $tags, $hooks);
            }
            foreach ($node->catchBody ?? [] as $s) {
                self::collect($s, $tags, $hooks);
            }
            foreach ($node->finallyBody ?? [] as $s) {
                self::collect($s, $tags, $hooks);
            }

            return;
        }
        if ($node instanceof Await) {
            self::collect($node->expr, $tags, $hooks);

            return;
        }
        if ($node instanceof Assign) {
            self::collect($node->target, $tags, $hooks);
            self::collect($node->value, $tags, $hooks);

            return;
        }
        if ($node instanceof Binary) {
            self::collect($node->left, $tags, $hooks);
            self::collect($node->right, $tags, $hooks);

            return;
        }
        if ($node instanceof Unary) {
            self::collect($node->expr, $tags, $hooks);

            return;
        }
        if ($node instanceof Ternary) {
            self::collect($node->cond, $tags, $hooks);
            if ($node->then !== null) {
                self::collect($node->then, $tags, $hooks);
            }
            self::collect($node->else, $tags, $hooks);

            return;
        }
        if ($node instanceof Call) {
            if ($node->callee instanceof Identifier) {
                $hooks[] = $node->callee->name;
            }
            self::collect($node->callee, $tags, $hooks);
            foreach ($node->args as $arg) {
                self::collect($arg, $tags, $hooks);
            }

            return;
        }
        if ($node instanceof MemberAccess) {
            self::collect($node->object, $tags, $hooks);

            return;
        }
        if ($node instanceof ArrayDim) {
            self::collect($node->array, $tags, $hooks);
            if ($node->dim !== null) {
                self::collect($node->dim, $tags, $hooks);
            }

            return;
        }
        if ($node instanceof ArrowFunction) {
            self::collect($node->body, $tags, $hooks);

            return;
        }
        if ($node instanceof ArrayLiteral) {
            foreach ($node->items as $item) {
                /** @var ArrayItem $item */
                if ($item->key !== null) {
                    self::collect($item->key, $tags, $hooks);
                }
                self::collect($item->value, $tags, $hooks);
            }

            return;
        }
        if ($node instanceof JsxElement) {
            $tags[] = $node->tagName;
            foreach ($node->attributes as $attr) {
                if ($attr->value !== null) {
                    self::collect($attr->value, $tags, $hooks);
                }
            }
            foreach ($node->children as $child) {
                self::collect($child, $tags, $hooks);
            }

            return;
        }
        if ($node instanceof JsxExpressionContainer) {
            self::collect($node->expr, $tags, $hooks);

            return;
        }
        if ($node instanceof Identifier) {
            if (ComponentRegistry::sourceFor($node->name) !== null) {
                $tags[] = $node->name;
            }

            return;
        }

        // Variable, Literal, JsxText: nós-folha, nada a coletar.
    }
}