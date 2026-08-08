<?php

declare(strict_types=1);

namespace Frost\Parser;

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
use Frost\Lexer\Token;
use Frost\Lexer\TokenKind;

/**
 * Parser recursive-descent escrito à mão. Não reusa PhpParser\Parser do
 * nikic/php-parser porque ele espera tokens no formato do token_get_all(),
 * incompatível com o stream que o Lexer do Frost produz (ver nota em
 * Frost\Ast\Node). Consome Token[] e devolve uma lista de statements
 * (Frost\Ast\Node[]).
 *
 * Escopo v1 (ver "débito técnico" na entrega do pacote):
 * - função top-level, return, expressão solta como statement
 * - expressões com precedência: atribuição, ternário, ??, ||, &&, ==/!=/===/!==,
 *   </>/<=/>=,  +/-/. , * / %, unário !/-/+, postfix ()/->/::/[ ]
 * - arrow function `fn(...) => expr`
 * - elemento JSX como expressão de primeira classe (aninhável em qualquer lugar
 *   onde uma expressão é aceita: return, atribuição, argumento de chamada, etc.)
 */
final class Parser
{
    /** @var Token[] */
    private readonly array $tokens;
    private int $pos = 0;

    /** Valores de operador tratados como cada nível de precedência binária. */
    private const EQUALITY_OPS = ['==', '!=', '===', '!=='];
    private const COMPARISON_OPS = ['<', '>', '<=', '>='];
    private const ADDITIVE_OPS = ['+', '-', '.'];
    private const MULTIPLICATIVE_OPS = ['*', '/', '%'];
    private const UNARY_OPS = ['!', '-', '+'];

    /** @param Token[] $tokens tokens do Lexer (comentários são ignorados aqui) */
    public function __construct(array $tokens)
    {
        $this->tokens = array_values(array_filter(
            $tokens,
            static fn (Token $t) => $t->kind !== TokenKind::T_COMMENT,
        ));
    }

    /** @return Node[] lista de statements top-level */
    public function parseProgram(): array
    {
        $stmts = [];
        while (!$this->check(TokenKind::T_EOF)) {
            $stmts[] = $this->parseStatement();
        }

        return $stmts;
    }

    // ------------------------------------------------------------------
    // Statements
    // ------------------------------------------------------------------

    private function parseStatement(): Node
    {
        if ($this->check(TokenKind::T_ASYNC)) {
            $this->advance();

            return $this->parseFunctionDecl(true);
        }
        if ($this->check(TokenKind::T_FUNCTION)) {
            return $this->parseFunctionDecl();
        }
        if ($this->check(TokenKind::T_RETURN)) {
            return $this->parseReturnStmt();
        }
        if ($this->check(TokenKind::T_IF)) {
            return $this->parseIf();
        }
        if ($this->check(TokenKind::T_FOREACH)) {
            return $this->parseForeach();
        }
        if ($this->check(TokenKind::T_USE)) {
            return $this->parseUseStmt();
        }
        if ($this->check(TokenKind::T_TRY)) {
            return $this->parseTryStmt();
        }

        return $this->parseExpressionStmt();
    }

    /** @return Node[] */
    private function parseBlock(): array
    {
        $this->expectOperatorOrPunct(TokenKind::T_OPEN_BRACE);
        $body = [];
        while (!$this->check(TokenKind::T_CLOSE_BRACE)) {
            $body[] = $this->parseStatement();
        }
        $this->expectOperatorOrPunct(TokenKind::T_CLOSE_BRACE);

        return $body;
    }

    private function parseIf(): IfStmt
    {
        $this->expect(TokenKind::T_IF);
        $this->expectOperatorOrPunct(TokenKind::T_OPEN_PAREN);
        $cond = $this->parseExpression();
        $this->expectOperatorOrPunct(TokenKind::T_CLOSE_PAREN);
        $thenBody = $this->parseBlock();

        return new IfStmt($cond, $thenBody, $this->parseOptionalElse());
    }

    /** @return Node[]|null */
    private function parseOptionalElse(): ?array
    {
        if ($this->check(TokenKind::T_ELSEIF)) {
            // `elseif (...) {...}` vira um único IfStmt dentro do elseBody —
            // mesma representação de `else { if (...) {...} }`, ver nota em IfStmt.
            $this->expect(TokenKind::T_ELSEIF);
            $this->expectOperatorOrPunct(TokenKind::T_OPEN_PAREN);
            $cond = $this->parseExpression();
            $this->expectOperatorOrPunct(TokenKind::T_CLOSE_PAREN);
            $thenBody = $this->parseBlock();

            return [new IfStmt($cond, $thenBody, $this->parseOptionalElse())];
        }

        if ($this->check(TokenKind::T_ELSE)) {
            $this->advance();
            if ($this->check(TokenKind::T_IF)) {
                return [$this->parseIf()];
            }

            return $this->parseBlock();
        }

        return null;
    }

    private function parseForeach(): ForeachStmt
    {
        $this->expect(TokenKind::T_FOREACH);
        $this->expectOperatorOrPunct(TokenKind::T_OPEN_PAREN);
        $iterable = $this->parseExpression();
        $this->expect(TokenKind::T_AS);

        $first = $this->expect(TokenKind::T_VARIABLE)->value;
        $keyVar = null;
        $valueVar = $first;

        if ($this->matchOperator('=>')) {
            $keyVar = $first;
            $valueVar = $this->expect(TokenKind::T_VARIABLE)->value;
        }

        $this->expectOperatorOrPunct(TokenKind::T_CLOSE_PAREN);
        $body = $this->parseBlock();

        return new ForeachStmt($iterable, $keyVar, $valueVar, $body);
    }

    private function parseUseStmt(): UseStmt
    {
        $this->expect(TokenKind::T_USE);
        $path = [$this->expect(TokenKind::T_IDENTIFIER)->value];
        while ($this->matchOperator('\\')) {
            $path[] = $this->expect(TokenKind::T_IDENTIFIER)->value;
        }

        $alias = null;
        if ($this->check(TokenKind::T_AS)) {
            $this->advance();
            $alias = $this->expect(TokenKind::T_IDENTIFIER)->value;
        }

        $this->expectOperatorOrPunct(TokenKind::T_SEMICOLON);

        return new UseStmt($path, $alias);
    }

    private function parseTryStmt(): TryStmt
    {
        $this->expect(TokenKind::T_TRY);
        $tryBody = $this->parseBlock();

        $catchVar = null;
        $catchBody = null;
        if ($this->check(TokenKind::T_CATCH)) {
            $this->advance();
            $this->expectOperatorOrPunct(TokenKind::T_OPEN_PAREN);
            // Descarta o tipo da exceção (\Exception, Throwable, TypeA|TypeB
            // etc.) — não validamos tipo nenhum no v1, só pulamos até achar
            // a variável ou o fechamento do parêntese.
            while (!$this->check(TokenKind::T_VARIABLE) && !$this->check(TokenKind::T_CLOSE_PAREN)) {
                $this->advance();
            }
            if ($this->check(TokenKind::T_VARIABLE)) {
                $catchVar = $this->advance()->value;
            }
            $this->expectOperatorOrPunct(TokenKind::T_CLOSE_PAREN);
            $catchBody = $this->parseBlock();
        }

        $finallyBody = null;
        if ($this->check(TokenKind::T_FINALLY)) {
            $this->advance();
            $finallyBody = $this->parseBlock();
        }

        return new TryStmt($tryBody, $catchVar, $catchBody, $finallyBody);
    }

    private function parseFunctionDecl(bool $isAsync = false): FunctionDecl
    {
        $this->expect(TokenKind::T_FUNCTION);
        $name = $this->expect(TokenKind::T_IDENTIFIER)->value;
        $this->expectOperatorOrPunct(TokenKind::T_OPEN_PAREN);
        $params = $this->parseParamList();
        $this->expectOperatorOrPunct(TokenKind::T_CLOSE_PAREN);
        $this->expectOperatorOrPunct(TokenKind::T_OPEN_BRACE);

        $body = [];
        while (!$this->check(TokenKind::T_CLOSE_BRACE)) {
            $body[] = $this->parseStatement();
        }
        $this->expectOperatorOrPunct(TokenKind::T_CLOSE_BRACE);

        return new FunctionDecl($name, $params, $body, $isAsync);
    }

    /** @return Param[] */
    private function parseParamList(): array
    {
        $params = [];
        if ($this->check(TokenKind::T_CLOSE_PAREN)) {
            return $params;
        }

        do {
            $varTok = $this->expect(TokenKind::T_VARIABLE);
            $default = null;
            if ($this->matchOperator('=')) {
                $default = $this->parseExpression();
            }
            $params[] = new Param($varTok->value, $default);
        } while ($this->matchOperatorOrPunct(TokenKind::T_COMMA));

        return $params;
    }

    private function parseReturnStmt(): ReturnStmt
    {
        $this->expect(TokenKind::T_RETURN);
        $expr = null;
        if (!$this->check(TokenKind::T_SEMICOLON)) {
            $expr = $this->parseExpression();
        }
        $this->expectOperatorOrPunct(TokenKind::T_SEMICOLON);

        return new ReturnStmt($expr);
    }

    private function parseExpressionStmt(): ExpressionStmt
    {
        $expr = $this->parseExpression();
        $this->expectOperatorOrPunct(TokenKind::T_SEMICOLON);

        return new ExpressionStmt($expr);
    }

    // ------------------------------------------------------------------
    // Expressões (precedência crescente: assignment é a mais fraca)
    // ------------------------------------------------------------------

    private function parseExpression(): Node
    {
        return $this->parseAssignment();
    }

    private function parseAssignment(): Node
    {
        $left = $this->parseTernary();
        if ($this->matchOperator('=')) {
            $right = $this->parseAssignment();

            return new Assign($left, $right);
        }

        return $left;
    }

    private function parseTernary(): Node
    {
        $cond = $this->parseNullCoalesce();
        if ($this->matchOperator('?')) {
            if ($this->matchOperator(':')) {
                $else = $this->parseAssignment();

                return new Ternary($cond, null, $else);
            }
            $then = $this->parseExpression();
            $this->expectOperatorValue(':');
            $else = $this->parseAssignment();

            return new Ternary($cond, $then, $else);
        }

        return $cond;
    }

    private function parseNullCoalesce(): Node
    {
        $left = $this->parseLogicalOr();
        while ($this->matchOperator('??')) {
            $left = new Binary('??', $left, $this->parseLogicalOr());
        }

        return $left;
    }

    private function parseLogicalOr(): Node
    {
        $left = $this->parseLogicalAnd();
        while ($this->matchOperator('||')) {
            $left = new Binary('||', $left, $this->parseLogicalAnd());
        }

        return $left;
    }

    private function parseLogicalAnd(): Node
    {
        $left = $this->parseEquality();
        while ($this->matchOperator('&&')) {
            $left = new Binary('&&', $left, $this->parseEquality());
        }

        return $left;
    }

    private function parseEquality(): Node
    {
        $left = $this->parseComparison();
        while (($op = $this->matchOperatorAny(self::EQUALITY_OPS)) !== null) {
            $left = new Binary($op, $left, $this->parseComparison());
        }

        return $left;
    }

    private function parseComparison(): Node
    {
        $left = $this->parseAdditive();
        while (($op = $this->matchOperatorAny(self::COMPARISON_OPS)) !== null) {
            $left = new Binary($op, $left, $this->parseAdditive());
        }

        return $left;
    }

    private function parseAdditive(): Node
    {
        $left = $this->parseMultiplicative();
        while (($op = $this->matchOperatorAny(self::ADDITIVE_OPS)) !== null) {
            $left = new Binary($op, $left, $this->parseMultiplicative());
        }

        return $left;
    }

    private function parseMultiplicative(): Node
    {
        $left = $this->parseUnary();
        while (($op = $this->matchOperatorAny(self::MULTIPLICATIVE_OPS)) !== null) {
            $left = new Binary($op, $left, $this->parseUnary());
        }

        return $left;
    }

    private function parseUnary(): Node
    {
        if ($this->check(TokenKind::T_AWAIT)) {
            $this->advance();

            return new Await($this->parseUnary());
        }
        if (($op = $this->matchOperatorAny(self::UNARY_OPS)) !== null) {
            return new Unary($op, $this->parseUnary());
        }

        return $this->parsePostfix();
    }

    private function parsePostfix(): Node
    {
        $expr = $this->parsePrimary();

        while (true) {
            if ($this->matchOperatorOrPunct(TokenKind::T_OPEN_PAREN)) {
                $args = $this->parseArgList();
                $this->expectOperatorOrPunct(TokenKind::T_CLOSE_PAREN);
                $expr = new Call($expr, $args);
                continue;
            }
            if ($this->matchOperator('->')) {
                $name = $this->expect(TokenKind::T_IDENTIFIER)->value;
                $expr = new MemberAccess($expr, $name, false);
                continue;
            }
            if ($this->matchOperator('::')) {
                $name = $this->expect(TokenKind::T_IDENTIFIER)->value;
                $expr = new MemberAccess($expr, $name, true);
                continue;
            }
            if ($this->matchOperatorOrPunct(TokenKind::T_OPEN_BRACKET)) {
                $dim = $this->check(TokenKind::T_CLOSE_BRACKET) ? null : $this->parseExpression();
                $this->expectOperatorOrPunct(TokenKind::T_CLOSE_BRACKET);
                $expr = new ArrayDim($expr, $dim);
                continue;
            }
            break;
        }

        return $expr;
    }

    /** @return Node[] */
    private function parseArgList(): array
    {
        $args = [];
        if ($this->check(TokenKind::T_CLOSE_PAREN)) {
            return $args;
        }
        do {
            $args[] = $this->parseExpression();
        } while ($this->matchOperatorOrPunct(TokenKind::T_COMMA));

        return $args;
    }

    private function parsePrimary(): Node
    {
        $tok = $this->current();

        switch ($tok->kind) {
            case TokenKind::T_VARIABLE:
                $this->advance();

                return new Variable($tok->value);

            case TokenKind::T_LNUMBER:
                $this->advance();

                return new Literal(LiteralKind::INT, (int) $tok->value);

            case TokenKind::T_DNUMBER:
                $this->advance();

                return new Literal(LiteralKind::FLOAT, (float) $tok->value);

            case TokenKind::T_CONSTANT_STRING:
                $this->advance();

                return new Literal(LiteralKind::STRING, self::unquote($tok->value));

            case TokenKind::T_TRUE:
                $this->advance();

                return new Literal(LiteralKind::BOOL, true);

            case TokenKind::T_FALSE:
                $this->advance();

                return new Literal(LiteralKind::BOOL, false);

            case TokenKind::T_NULL:
                $this->advance();

                return new Literal(LiteralKind::NULL, null);

            case TokenKind::T_FN:
                return $this->parseArrowFunction();

            case TokenKind::T_ASYNC:
                $this->advance();

                return $this->parseArrowFunction(true);

            case TokenKind::T_OPEN_PAREN:
                $this->advance();
                $expr = $this->parseExpression();
                $this->expectOperatorOrPunct(TokenKind::T_CLOSE_PAREN);

                return $expr;

            case TokenKind::T_JSX_TAG_OPEN:
                return $this->parseJsxElement();

            case TokenKind::T_OPEN_BRACKET:
                return $this->parseArrayLiteral();

            case TokenKind::T_IDENTIFIER:
                $this->advance();

                return new Identifier($tok->value);

            default:
                throw new ParserException(
                    "Token inesperado '{$tok->value}' ({$tok->kind->name})",
                    $tok->line,
                    $tok->column,
                );
        }
    }

    private function parseArrayLiteral(): ArrayLiteral
    {
        $this->expect(TokenKind::T_OPEN_BRACKET);
        $items = [];

        while (!$this->check(TokenKind::T_CLOSE_BRACKET)) {
            $first = $this->parseExpression();
            if ($this->matchOperator('=>')) {
                $value = $this->parseExpression();
                $items[] = new ArrayItem($first, $value);
            } else {
                $items[] = new ArrayItem(null, $first);
            }

            if (!$this->matchOperatorOrPunct(TokenKind::T_COMMA)) {
                break;
            }
        }

        $this->expect(TokenKind::T_CLOSE_BRACKET);

        return new ArrayLiteral($items);
    }

    private function parseArrowFunction(bool $isAsync = false): ArrowFunction
    {
        $this->expect(TokenKind::T_FN);
        $this->expectOperatorOrPunct(TokenKind::T_OPEN_PAREN);
        $params = $this->parseParamList();
        $this->expectOperatorOrPunct(TokenKind::T_CLOSE_PAREN);
        $this->expectOperatorValue('=>');
        $body = $this->parseExpression();

        return new ArrowFunction($params, $body, $isAsync);
    }

    private static function unquote(string $raw): string
    {
        $inner = substr($raw, 1, -1);

        // Débito: só resolve os escapes mais comuns. Interpolação de
        // variáveis (`"$nome"`, `"{$expr}"`) permanece literal — fica pro
        // Transpiler decidir o que fazer com isso.
        return str_replace(
            ['\\\\', '\\"', "\\'", '\\n', '\\t'],
            ['\\', '"', "'", "\n", "\t"],
            $inner,
        );
    }

    // ------------------------------------------------------------------
    // JSX
    // ------------------------------------------------------------------

    private function parseJsxElement(): JsxElement
    {
        $openTok = $this->expect(TokenKind::T_JSX_TAG_OPEN);
        $tagName = $openTok->value;

        $attributes = [];
        while ($this->check(TokenKind::T_JSX_ATTR_NAME)) {
            $nameTok = $this->advance();
            $value = null;

            if ($this->check(TokenKind::T_JSX_ATTR_EQUALS)) {
                $this->advance();
                $valTok = $this->current();

                if ($valTok->kind === TokenKind::T_CONSTANT_STRING) {
                    $this->advance();
                    $value = new Literal(LiteralKind::STRING, self::unquote($valTok->value));
                } elseif ($valTok->kind === TokenKind::T_JSX_EXPR_START) {
                    $this->advance();
                    $value = $this->parseExpression();
                    $this->expect(TokenKind::T_JSX_EXPR_END);
                } else {
                    throw new ParserException(
                        "Valor de atributo JSX inválido após '='",
                        $valTok->line,
                        $valTok->column,
                    );
                }
            }

            $attributes[] = new JsxAttribute($nameTok->value, $value);
        }

        if ($this->matchOperatorOrPunct(TokenKind::T_JSX_TAG_SELFCLOSE_END)) {
            return new JsxElement($tagName, $attributes, [], true);
        }

        $this->expect(TokenKind::T_JSX_GT);

        $children = [];
        while (!$this->check(TokenKind::T_JSX_TAG_CLOSE)) {
            $tok = $this->current();

            if ($tok->kind === TokenKind::T_JSX_TEXT) {
                $this->advance();
                $children[] = new JsxText($tok->value);
            } elseif ($tok->kind === TokenKind::T_JSX_EXPR_START) {
                $this->advance();
                $inner = $this->parseExpression();
                $this->expect(TokenKind::T_JSX_EXPR_END);
                $children[] = new JsxExpressionContainer($inner);
            } elseif ($tok->kind === TokenKind::T_JSX_TAG_OPEN) {
                $children[] = $this->parseJsxElement();
            } else {
                throw new ParserException(
                    "Token inesperado dentro do conteúdo JSX: {$tok->kind->name}",
                    $tok->line,
                    $tok->column,
                );
            }
        }

        $this->expect(TokenKind::T_JSX_TAG_CLOSE);

        return new JsxElement($tagName, $attributes, $children, false);
    }

    // ------------------------------------------------------------------
    // Helpers de stream de tokens
    // ------------------------------------------------------------------

    private function current(): Token
    {
        return $this->tokens[$this->pos];
    }

    private function advance(): Token
    {
        $t = $this->tokens[$this->pos];
        if ($t->kind !== TokenKind::T_EOF) {
            $this->pos++;
        }

        return $t;
    }

    private function check(TokenKind $kind): bool
    {
        return $this->current()->kind === $kind;
    }

    private function expect(TokenKind $kind): Token
    {
        if (!$this->check($kind)) {
            $tok = $this->current();
            throw new ParserException(
                "Esperado {$kind->name}, encontrado {$tok->kind->name} ('{$tok->value}')",
                $tok->line,
                $tok->column,
            );
        }

        return $this->advance();
    }

    /** Igual a expect(), só nomeado assim nos call sites que são pontuação/operador estrutural. */
    private function expectOperatorOrPunct(TokenKind $kind): Token
    {
        return $this->expect($kind);
    }

    private function matchOperatorOrPunct(TokenKind $kind): bool
    {
        if ($this->check($kind)) {
            $this->advance();

            return true;
        }

        return false;
    }

    /** Casa um T_OPERATOR com valor específico (ex: '=', '=>', '?', '??'). */
    private function matchOperator(string $value): bool
    {
        $tok = $this->current();
        if ($tok->kind === TokenKind::T_OPERATOR && $tok->value === $value) {
            $this->advance();

            return true;
        }

        return false;
    }

    /** @param string[] $values @return string|null o valor casado, ou null */
    private function matchOperatorAny(array $values): ?string
    {
        $tok = $this->current();
        if ($tok->kind === TokenKind::T_OPERATOR && in_array($tok->value, $values, true)) {
            $this->advance();

            return $tok->value;
        }

        return null;
    }

    private function expectOperatorValue(string $value): Token
    {
        if (!$this->matchOperator($value)) {
            $tok = $this->current();
            throw new ParserException(
                "Esperado operador '{$value}', encontrado '{$tok->value}'",
                $tok->line,
                $tok->column,
            );
        }

        return $this->tokens[$this->pos - 1];
    }
}