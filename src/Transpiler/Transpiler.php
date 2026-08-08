<?php

declare(strict_types=1);

namespace Frost\Transpiler;

use Frost\Expr\ArrayDim;
use Frost\Expr\ArrayItem;
use Frost\Expr\ArrayLiteral;
use Frost\Expr\ArrowFunction;
use Frost\Expr\Assign;
use Frost\Expr\Await;
use Frost\Expr\Binary;
use Frost\Expr\Call;
use Frost\Expr\Identifier;
use Frost\Expr\Literal;
use Frost\Expr\LiteralKind;
use Frost\Expr\MemberAccess;
use Frost\Expr\Ternary;
use Frost\Expr\Unary;
use Frost\Expr\Variable;
use Frost\Ast\Jsx\JsxAttribute;
use Frost\Ast\Jsx\JsxElement;
use Frost\Ast\Jsx\JsxExpressionContainer;
use Frost\Ast\Jsx\JsxText;
use Frost\Ast\Node;
use Frost\Ast\Param;
use Frost\Ast\Stmt\ExpressionStmt;
use Frost\Ast\Stmt\ForeachStmt;
use Frost\Ast\Stmt\FunctionDecl;
use Frost\Ast\Stmt\IfStmt;
use Frost\Ast\Stmt\ReturnStmt;
use Frost\Ast\Stmt\TryStmt;
use Frost\Ast\Stmt\UseStmt;

/**
 * Converte a AST do Frost numa string JS/JSX que o Metro (bundler do React
 * Native) consegue processar diretamente — o Babel do RN já sabe compilar
 * JSX, então emitimos JSX de verdade em vez de React.createElement(...).
 * Isso também significa que NÃO reimplementamos as regras de whitespace do
 * JSX (colapso de espaços/linhas em branco entre tags): passamos o texto
 * capturado pelo Lexer como está, e o Babel do RN aplica as regras reais
 * quando processar o arquivo gerado.
 *
 * Mapeamento de operadores PHP -> JS (débitos documentados no fim do arquivo):
 * - '.' (concat PHP)      -> '+'   (concat JS)
 * - '->' e '::'           -> '.'   (JS não distingue acesso de instância/estático)
 * - ternário curto '?:'    -> '||' (aproximação; ver debt)
 */
final class Transpiler
{
    private int $indent = 0;

    /** @var array<int, array<string, bool>> pilha de escopos: nome da variável já declarada nesse escopo? */
    private array $scopeStack = [];

    /** @param Node[] $stmts */
    public function transpileProgram(array $stmts): string
    {
        $this->pushScope();
        $out = '';
        foreach ($stmts as $stmt) {
            $out .= $this->transpileStmt($stmt);
        }
        $this->popScope();

        return $out;
    }

    private function pushScope(): void
    {
        $this->scopeStack[] = [];
    }

    private function popScope(): void
    {
        array_pop($this->scopeStack);
    }

    private function isDeclared(string $name): bool
    {
        // Precisa checar a pilha INTEIRA (de dentro pra fora), não só o
        // escopo mais interno — senão uma variável hoisted no topo da
        // função continua "não declarada" do ponto de vista de dentro de
        // um if/foreach aninhado, e ganha `let` de novo ali (bug real que
        // apareceu testando: `status` virava 3 variáveis diferentes, uma
        // por branch do if/elseif/else, exatamente o problema que o
        // hoisting deveria ter resolvido).
        for ($i = count($this->scopeStack) - 1; $i >= 0; $i--) {
            if (isset($this->scopeStack[$i][$name])) {
                return true;
            }
        }

        return false;
    }

    private function declare(string $name): void
    {
        $idx = count($this->scopeStack) - 1;
        $this->scopeStack[$idx][$name] = true;
    }

    // ------------------------------------------------------------------
    // Statements
    // ------------------------------------------------------------------

    private function transpileStmt(Node $node): string
    {
        return match (true) {
            $node instanceof FunctionDecl => $this->stmtFunctionDecl($node),
            $node instanceof ReturnStmt => $this->pad("return" . ($node->expr ? ' ' . $this->expr($node->expr) : '') . ";\n"),
            $node instanceof ExpressionStmt => $this->pad($this->expressionStatementBody($node->expr) . ";\n"),
            $node instanceof IfStmt => $this->stmtIf($node),
            $node instanceof ForeachStmt => $this->stmtForeach($node),
            $node instanceof TryStmt => $this->stmtTry($node),
            $node instanceof UseStmt => '', // import entre componentes é resolvido pelo CLI (Compiler::compileTree), não aqui
            default => throw new \RuntimeException('Statement não suportado: ' . $node::class),
        };
    }

    private function stmtTry(TryStmt $node): string
    {
        $out = $this->pad("try {\n");
        $this->indent++;
        $this->pushScope();
        foreach ($node->tryBody as $s) {
            $out .= $this->transpileStmt($s);
        }
        $this->popScope();
        $this->indent--;
        $out .= $this->pad('}');

        if ($node->catchBody !== null) {
            // catch sem variável em PHP (`catch (\Exception) {}`) é válido,
            // mas JS exige um identificador — usamos 'e' como fallback.
            $catchVar = $node->catchVar ?? 'e';
            $out .= " catch ({$catchVar}) {\n";
            $this->indent++;
            $this->pushScope();
            $this->declare($catchVar);
            foreach ($node->catchBody as $s) {
                $out .= $this->transpileStmt($s);
            }
            $this->popScope();
            $this->indent--;
            $out .= $this->pad('}');
        }

        if ($node->finallyBody !== null) {
            $out .= " finally {\n";
            $this->indent++;
            $this->pushScope();
            foreach ($node->finallyBody as $s) {
                $out .= $this->transpileStmt($s);
            }
            $this->popScope();
            $this->indent--;
            $out .= $this->pad('}');
        }

        return $out . "\n";
    }

    private function stmtIf(IfStmt $node): string
    {
        $out = $this->pad('if (' . $this->expr($node->cond) . ") {\n");
        $this->indent++;
        $this->pushScope();
        foreach ($node->thenBody as $s) {
            $out .= $this->transpileStmt($s);
        }
        $this->popScope();
        $this->indent--;
        $out .= $this->pad('}');

        if ($node->elseBody === null) {
            return $out . "\n";
        }

        // `else { if (...) }` (nosso jeito de representar elseif) fica na
        // mesma linha, igual PHP/JS de verdade formatam: `} else if (...) {`
        if (count($node->elseBody) === 1 && $node->elseBody[0] instanceof IfStmt) {
            $innerTrimmed = ltrim($this->stmtIf($node->elseBody[0]));

            return $out . ' else ' . $innerTrimmed;
        }

        $out .= " else {\n";
        $this->indent++;
        $this->pushScope();
        foreach ($node->elseBody as $s) {
            $out .= $this->transpileStmt($s);
        }
        $this->popScope();
        $this->indent--;
        $out .= $this->pad("}\n");

        return $out;
    }

    private function stmtForeach(ForeachStmt $node): string
    {
        $iterable = $this->expr($node->iterable);

        if ($node->keyVar !== null) {
            // com chave -> percorre pares [chave, valor] via Object.entries.
            // Débito: em lista (não associativa), a chave sai como string
            // ("0","1",...) igual o Object.entries do JS já faz — diferente
            // do PHP, onde a chave de lista é int.
            $out = $this->pad("for (const [{$node->keyVar}, {$node->valueVar}] of Object.entries({$iterable})) {\n");
        } else {
            $out = $this->pad("for (const {$node->valueVar} of {$iterable}) {\n");
        }

        $this->indent++;
        $this->pushScope();
        if ($node->keyVar !== null) {
            $this->declare($node->keyVar);
        }
        $this->declare($node->valueVar);
        foreach ($node->body as $s) {
            $out .= $this->transpileStmt($s);
        }
        $this->popScope();
        $this->indent--;
        $out .= $this->pad("}\n");

        return $out;
    }

    /**
     * Atribuição simples a uma variável ainda não declarada neste escopo vira
     * `let nome = valor`; reatribuição (ou atribuição a algo que não é uma
     * variável simples, ex: $obj->prop = x) vira só `nome = valor`.
     *
     * Casos especiais:
     * - `$x = useState(inicial)` vira `let [x, setX] = useState(inicial)`.
     * - `$arr[] = x` (PHP "empurra pro fim do array") vira `arr.push(x)` —
     *   sem isso viraria `arr[] = x`, que é JS inválido.
     */
    private function expressionStatementBody(Node $expr): string
    {
        if ($expr instanceof Assign && $expr->target instanceof ArrayDim && $expr->target->dim === null) {
            return $this->expr($expr->target->array) . '.push(' . $this->expr($expr->value) . ')';
        }

        if ($expr instanceof Assign && $expr->target instanceof Variable) {
            $name = $expr->target->name;

            if ($this->isCallTo($expr->value, 'useState')) {
                $setter = 'set' . ucfirst($name);
                $callStr = $this->expr($expr->value);

                if ($this->isDeclared($name)) {
                    // já hoisted no topo da função -> só destructuring assignment, sem 'let'
                    return "[{$name}, {$setter}] = {$callStr}";
                }

                $this->declare($name);
                $this->declare($setter);

                return "let [{$name}, {$setter}] = {$callStr}";
            }

            $valueStr = $this->expr($expr->value);
            if (!$this->isDeclared($name)) {
                $this->declare($name);

                return "let {$name} = {$valueStr}";
            }

            return "{$name} = {$valueStr}";
        }

        return $this->expr($expr);
    }

    private function isCallTo(Node $node, string $calleeName): bool
    {
        return $node instanceof Call
            && $node->callee instanceof Identifier
            && $node->callee->name === $calleeName;
    }

    private function stmtFunctionDecl(FunctionDecl $node): string
    {
        $params = implode(', ', array_map(fn (Param $p) => $this->param($p), $node->params));
        $asyncPrefix = $node->isAsync ? 'async ' : '';
        $out = $this->pad("{$asyncPrefix}function {$node->name}({$params}) {\n");
        $this->indent++;
        $this->pushScope();
        foreach ($node->params as $p) {
            $this->declare($p->name);
        }

        // Hoisting: PHP não tem escopo de bloco pra if/foreach (variável
        // atribuída só dentro de um if continua acessível depois dele), mas
        // JS com `let` tem. Sem isso, `if (x) { $status = "a"; } ... {$status}`
        // gera JS que lança ReferenceError. Então declaramos tudo que é
        // atribuído em qualquer lugar do corpo (recursivamente, incluindo
        // dentro de if/foreach) já no topo da função, e as atribuições em si
        // viram reatribuição simples, sem `let` de novo.
        $paramNames = array_map(fn (Param $p) => $p->name, $node->params);
        $hoisted = array_values(array_diff(array_unique($this->collectAssignedNames($node->body)), $paramNames));
        foreach ($hoisted as $name) {
            $this->declare($name);
        }
        if ($hoisted !== []) {
            $out .= $this->pad('let ' . implode(', ', $hoisted) . ";\n");
        }

        foreach ($node->body as $bodyStmt) {
            $out .= $this->transpileStmt($bodyStmt);
        }
        $this->popScope();
        $this->indent--;
        $out .= $this->pad("}\n");

        return $out;
    }

    private function param(Param $p): string
    {
        $s = $p->name;
        if ($p->default !== null) {
            $s .= ' = ' . $this->expr($p->default);
        }

        return $s;
    }

    /**
     * Varre recursivamente uma lista de statements (incluindo dentro de
     * if/foreach, mas SEM entrar em function declarations aninhadas — essas
     * têm escopo próprio) coletando todo nome de variável que recebe
     * atribuição simples em algum ponto. Usado pelo hoisting em
     * stmtFunctionDecl().
     *
     * @param Node[] $stmts
     * @return string[]
     */
    private function collectAssignedNames(array $stmts): array
    {
        $names = [];
        foreach ($stmts as $stmt) {
            if ($stmt instanceof ExpressionStmt && $stmt->expr instanceof Assign) {
                $target = $stmt->expr->target;
                if ($target instanceof Variable) {
                    $names[] = $target->name;
                    if ($this->isCallTo($stmt->expr->value, 'useState')) {
                        $names[] = 'set' . ucfirst($target->name);
                    }
                }
            }
            if ($stmt instanceof IfStmt) {
                $names = array_merge($names, $this->collectAssignedNames($stmt->thenBody));
                if ($stmt->elseBody !== null) {
                    $names = array_merge($names, $this->collectAssignedNames($stmt->elseBody));
                }
            }
            if ($stmt instanceof ForeachStmt) {
                $names = array_merge($names, $this->collectAssignedNames($stmt->body));
            }
            if ($stmt instanceof TryStmt) {
                $names = array_merge($names, $this->collectAssignedNames($stmt->tryBody));
                if ($stmt->catchBody !== null) {
                    $names = array_merge($names, $this->collectAssignedNames($stmt->catchBody));
                }
                if ($stmt->finallyBody !== null) {
                    $names = array_merge($names, $this->collectAssignedNames($stmt->finallyBody));
                }
            }
        }

        return $names;
    }

    // ------------------------------------------------------------------
    // Expressões
    // ------------------------------------------------------------------

    private function expr(Node $node): string
    {
        return match (true) {
            $node instanceof Variable => $node->name,
            $node instanceof Identifier => $node->name,
            $node instanceof Literal => $this->literal($node),
            $node instanceof Assign => $this->expr($node->target) . ' = ' . $this->expr($node->value),
            $node instanceof Binary => $this->binary($node),
            $node instanceof Unary => $node->op . $this->expr($node->expr),
            $node instanceof Await => 'await ' . $this->expr($node->expr),
            $node instanceof Ternary => $this->ternary($node),
            $node instanceof Call => $this->expr($node->callee) . '(' . implode(', ', array_map(fn ($a) => $this->expr($a), $node->args)) . ')',
            $node instanceof MemberAccess => $this->expr($node->object) . '.' . $node->member,
            $node instanceof ArrayDim => $this->expr($node->array) . '[' . ($node->dim ? $this->expr($node->dim) : '') . ']',
            $node instanceof ArrowFunction => $this->arrowFunction($node),
            $node instanceof ArrayLiteral => $this->arrayLiteral($node),
            $node instanceof JsxElement => $this->jsxElement($node),
            default => throw new \RuntimeException('Expressão não suportada: ' . $node::class),
        };
    }

    private function literal(Literal $node): string
    {
        return match ($node->kind) {
            LiteralKind::STRING => json_encode($node->value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LiteralKind::INT, LiteralKind::FLOAT => (string) $node->value,
            LiteralKind::BOOL => $node->value ? 'true' : 'false',
            LiteralKind::NULL => 'null',
        };
    }

    private function binary(Binary $node): string
    {
        // '.' é concatenação em PHP; em JS o operador de concatenação de string é '+'.
        $op = $node->op === '.' ? '+' : $node->op;

        return '(' . $this->expr($node->left) . ' ' . $op . ' ' . $this->expr($node->right) . ')';
    }

    private function ternary(Ternary $node): string
    {
        if ($node->then === null) {
            // Forma curta `$cond ?: $else` — aproximação semântica, ver debt no cabeçalho do arquivo.
            return '(' . $this->expr($node->cond) . ' || ' . $this->expr($node->else) . ')';
        }

        return '(' . $this->expr($node->cond) . ' ? ' . $this->expr($node->then) . ' : ' . $this->expr($node->else) . ')';
    }

    private function arrayLiteral(ArrayLiteral $node): string
    {
        if ($node->items === []) {
            return '[]';
        }

        $allKeyed = array_reduce($node->items, fn ($c, ArrayItem $i) => $c && $i->key !== null, true);
        $allPositional = array_reduce($node->items, fn ($c, ArrayItem $i) => $c && $i->key === null, true);

        if ($allKeyed) {
            $parts = array_map(
                fn (ArrayItem $i) => $this->objectKey($i->key) . ': ' . $this->expr($i->value),
                $node->items,
            );

            return '{ ' . implode(', ', $parts) . ' }';
        }

        if ($allPositional) {
            $parts = array_map(fn (ArrayItem $i) => $this->expr($i->value), $node->items);

            return '[' . implode(', ', $parts) . ']';
        }

        throw new \RuntimeException(
            'Array literal misto (alguns itens com chave, outros sem) não é suportado no v1 — '
            . 'use só array associativo (todas as chaves) ou só lista (nenhuma chave).',
        );
    }

    private function objectKey(Node $key): string
    {
        if ($key instanceof Literal && $key->kind === LiteralKind::STRING) {
            $value = (string) $key->value;
            if (preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $value) === 1) {
                return $value;
            }

            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // Chave dinâmica (variável, chamada, etc.) -> computed property name do JS.
        return '[' . $this->expr($key) . ']';
    }

    private function arrowFunction(ArrowFunction $node): string
    {
        $params = implode(', ', array_map(fn (Param $p) => $this->param($p), $node->params));
        $asyncPrefix = $node->isAsync ? 'async ' : '';

        return "{$asyncPrefix}({$params}) => " . $this->expr($node->body);
    }

    // ------------------------------------------------------------------
    // JSX
    // ------------------------------------------------------------------

    private function jsxElement(JsxElement $node): string
    {
        $attrs = '';
        foreach ($node->attributes as $attr) {
            $attrs .= ' ' . $this->jsxAttribute($attr);
        }

        if ($node->selfClosing) {
            return "<{$node->tagName}{$attrs} />";
        }

        $children = '';
        foreach ($node->children as $child) {
            $children .= match (true) {
                $child instanceof JsxText => $child->value,
                $child instanceof JsxExpressionContainer => '{' . $this->expr($child->expr) . '}',
                $child instanceof JsxElement => $this->jsxElement($child),
                default => throw new \RuntimeException('Filho JSX não suportado: ' . $child::class),
            };
        }

        return "<{$node->tagName}{$attrs}>{$children}</{$node->tagName}>";
    }

    private function jsxAttribute(JsxAttribute $attr): string
    {
        if ($attr->value === null) {
            return $attr->name;
        }

        // Atributo com literal string simples vira "valor" (JSX de verdade),
        // qualquer outra coisa vira {expressão}.
        if ($attr->value instanceof Literal && $attr->value->kind === LiteralKind::STRING) {
            return $attr->name . '="' . str_replace('"', '&quot;', (string) $attr->value->value) . '"';
        }

        return $attr->name . '={' . $this->expr($attr->value) . '}';
    }

    private function pad(string $line): string
    {
        return str_repeat('  ', $this->indent) . $line;
    }
}