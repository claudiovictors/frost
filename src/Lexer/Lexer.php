<?php

declare(strict_types=1);

namespace Frost\Lexer;

/**
 * Lexer do Frost: tokeniza um arquivo .php com sintaxe JSX-like embutida.
 *
 * Por que não delegamos ao token_get_all() nativo / lexer do nikic/php-parser:
 * texto literal entre tags JSX (ex: <Text>Contagem: {$count}</Text>) não é PHP
 * válido, então o tokenizer nativo simplesmente quebra ao encontrá-lo. Em vez
 * de tentar "consertar" isso por fora, o Lexer aqui assume o char-stream
 * completo e decide, via uma pilha de contextos, quando está em modo PHP e
 * quando está em modo JSX.
 *
 * Pilha de contextos (não recursão de função — mais fácil de testar):
 * - 'php'          : modo PHP normal (base da pilha, nunca é removida)
 * - 'jsx_open_tag' : dentro de <Tag ...> até o '>' ou '/>'
 * - 'jsx_children' : dentro do corpo de uma tag aberta, até a tag de fechamento
 * - 'jsx_expr'     : dentro de um {...} (valor de atributo ou filho), reusa o
 *                    tokenizer PHP normal e conta chaves pra saber quando fecha
 */
final class Lexer
{
    private readonly string $src;
    private readonly int $len;
    private int $pos = 0;
    private int $line = 1;
    private int $col = 1;

    /** @var Token[] */
    private array $tokens = [];

    /** @var array<int, array{type: string, tag?: string, depth?: int}> */
    private array $contextStack = [];

    private ?TokenKind $lastKind = null;
    private ?string $lastValue = null;

    private const KEYWORDS = [
        'function' => TokenKind::T_FUNCTION,
        'return' => TokenKind::T_RETURN,
        'if' => TokenKind::T_IF,
        'else' => TokenKind::T_ELSE,
        'elseif' => TokenKind::T_ELSEIF,
        'foreach' => TokenKind::T_FOREACH,
        'for' => TokenKind::T_FOR,
        'while' => TokenKind::T_WHILE,
        'do' => TokenKind::T_DO,
        'class' => TokenKind::T_CLASS,
        'extends' => TokenKind::T_EXTENDS,
        'implements' => TokenKind::T_IMPLEMENTS,
        'interface' => TokenKind::T_INTERFACE,
        'trait' => TokenKind::T_TRAIT,
        'abstract' => TokenKind::T_ABSTRACT,
        'final' => TokenKind::T_FINAL,
        'readonly' => TokenKind::T_READONLY,
        'public' => TokenKind::T_PUBLIC,
        'private' => TokenKind::T_PRIVATE,
        'protected' => TokenKind::T_PROTECTED,
        'static' => TokenKind::T_STATIC,
        'const' => TokenKind::T_CONST,
        'new' => TokenKind::T_NEW,
        'use' => TokenKind::T_USE,
        'namespace' => TokenKind::T_NAMESPACE,
        'echo' => TokenKind::T_ECHO,
        'print' => TokenKind::T_PRINT,
        'true' => TokenKind::T_TRUE,
        'false' => TokenKind::T_FALSE,
        'null' => TokenKind::T_NULL,
        'array' => TokenKind::T_ARRAY,
        'fn' => TokenKind::T_FN,
        'match' => TokenKind::T_MATCH,
        'break' => TokenKind::T_BREAK,
        'continue' => TokenKind::T_CONTINUE,
        'switch' => TokenKind::T_SWITCH,
        'case' => TokenKind::T_CASE,
        'default' => TokenKind::T_DEFAULT,
        'try' => TokenKind::T_TRY,
        'catch' => TokenKind::T_CATCH,
        'finally' => TokenKind::T_FINALLY,
        'throw' => TokenKind::T_THROW,
        'global' => TokenKind::T_GLOBAL,
        'instanceof' => TokenKind::T_INSTANCEOF,
        'enum' => TokenKind::T_ENUM,
        'yield' => TokenKind::T_YIELD,
        'and' => TokenKind::T_AND,
        'or' => TokenKind::T_OR,
        'as' => TokenKind::T_AS,
        'async' => TokenKind::T_ASYNC,
        'await' => TokenKind::T_AWAIT,
    ];

    private const PUNCT_SINGLE = [
        '(' => TokenKind::T_OPEN_PAREN,
        ')' => TokenKind::T_CLOSE_PAREN,
        '{' => TokenKind::T_OPEN_BRACE,
        '}' => TokenKind::T_CLOSE_BRACE,
        '[' => TokenKind::T_OPEN_BRACKET,
        ']' => TokenKind::T_CLOSE_BRACKET,
        ';' => TokenKind::T_SEMICOLON,
        ',' => TokenKind::T_COMMA,
    ];

    private const OPS3 = ['<=>', '===', '!==', '**=', '??=', '...', '<<=', '>>=', '?->'];
    private const OPS2 = [
        '==', '!=', '<>', '<=', '>=', '&&', '||', '??', '->', '=>', '::',
        '++', '--', '+=', '-=', '*=', '/=', '.=', '%=', '&=', '|=', '^=', '<<', '>>',
    ];
    private const OPS1 = ['+', '-', '*', '/', '%', '.', '=', '<', '>', '!', '&', '|', '^', '~', '?', ':', '@', '\\'];

    /** Tokens depois dos quais é permitido iniciar uma tag JSX com '<'. */
    private const JSX_ALLOWED_AFTER_KIND = [
        TokenKind::T_RETURN,
        TokenKind::T_OPEN_PAREN,
        TokenKind::T_OPEN_BRACE,
        TokenKind::T_OPEN_BRACKET,
        TokenKind::T_COMMA,
        TokenKind::T_SEMICOLON,
        TokenKind::T_JSX_TAG_CLOSE,
        TokenKind::T_JSX_TAG_SELFCLOSE_END,
        TokenKind::T_JSX_EXPR_START,
    ];

    /** Valores de T_OPERATOR depois dos quais é permitido iniciar uma tag JSX. */
    private const JSX_ALLOWED_AFTER_OPERATOR = ['=', '=>', '?', ':', '??', '&&', '||'];

    public function __construct(string $source)
    {
        $this->src = $this->stripPhpTags($source);
        $this->len = strlen($this->src);
    }

    /** @return Token[] */
    public function tokenize(): array
    {
        $this->contextStack[] = ['type' => 'php'];

        while (!$this->isAtEnd()) {
            $top = $this->contextStack[count($this->contextStack) - 1];
            match ($top['type']) {
                'php', 'jsx_expr' => $this->lexPhpToken(),
                'jsx_open_tag' => $this->lexJsxOpenTagStep(),
                'jsx_children' => $this->lexJsxChildStep(),
            };
        }

        if (count($this->contextStack) > 1) {
            $unclosed = end($this->contextStack);
            throw new LexerException(
                "Tag ou expressão JSX não fechada (contexto: {$unclosed['type']})",
                $this->line,
                $this->col,
            );
        }

        $this->emit(TokenKind::T_EOF, '', $this->line, $this->col, $this->pos);

        return $this->tokens;
    }

    // ------------------------------------------------------------------
    // Pré-processamento
    // ------------------------------------------------------------------

    private function stripPhpTags(string $src): string
    {
        $src = preg_replace('/^\xEF\xBB\xBF/', '', $src) ?? $src;

        if (preg_match('/^\s*<\?php\b/i', $src, $m, PREG_OFFSET_CAPTURE)) {
            $src = substr($src, $m[0][1] + strlen($m[0][0]));
        }

        $trimmedRight = rtrim($src);
        if (str_ends_with($trimmedRight, '?>')) {
            $src = substr($trimmedRight, 0, -2);
        }

        return $src;
    }

    // ------------------------------------------------------------------
    // Helpers de caractere
    // ------------------------------------------------------------------

    private function peek(int $offset = 0): ?string
    {
        $p = $this->pos + $offset;

        return $p < $this->len ? $this->src[$p] : null;
    }

    private function peekChunk(int $n): ?string
    {
        if ($this->pos + $n > $this->len) {
            return null;
        }

        return substr($this->src, $this->pos, $n);
    }

    private function advanceChar(): string
    {
        $c = $this->src[$this->pos];
        $this->pos++;
        if ($c === "\n") {
            $this->line++;
            $this->col = 1;
        } else {
            $this->col++;
        }

        return $c;
    }

    private function isAtEnd(int $offset = 0): bool
    {
        return $this->pos + $offset >= $this->len;
    }

    private function isIdentStart(?string $c): bool
    {
        return $c !== null && (ctype_alpha($c) || $c === '_');
    }

    private function isIdentPart(?string $c): bool
    {
        return $c !== null && (ctype_alnum($c) || $c === '_');
    }

    private function emit(TokenKind $kind, string $value, int $line, int $col, int $offset): Token
    {
        $t = new Token($kind, $value, $line, $col, $offset);
        $this->tokens[] = $t;
        $this->lastKind = $kind;
        $this->lastValue = $value;

        return $t;
    }

    // ------------------------------------------------------------------
    // Modo PHP (também usado dentro de jsx_expr)
    // ------------------------------------------------------------------

    private function lexPhpToken(): void
    {
        $this->skipWhitespace();
        if ($this->isAtEnd()) {
            return;
        }

        $line = $this->line;
        $col = $this->col;
        $offset = $this->pos;
        $c = $this->peek();

        if ($c === '/' && $this->peek(1) === '/') {
            $this->skipLineComment();

            return;
        }
        if ($c === '#') {
            $this->skipLineComment();

            return;
        }
        if ($c === '/' && $this->peek(1) === '*') {
            $this->skipBlockComment();

            return;
        }

        // Contabilidade de chaves quando estamos dentro de uma expressão JSX {...}
        $topIdx = count($this->contextStack) - 1;
        if ($this->contextStack[$topIdx]['type'] === 'jsx_expr') {
            if ($c === '{') {
                $this->advanceChar();
                $this->contextStack[$topIdx]['depth']++;
                $this->emit(TokenKind::T_OPEN_BRACE, '{', $line, $col, $offset);

                return;
            }
            if ($c === '}') {
                $this->advanceChar();
                if ($this->contextStack[$topIdx]['depth'] === 0) {
                    array_pop($this->contextStack);
                    $this->emit(TokenKind::T_JSX_EXPR_END, '}', $line, $col, $offset);
                } else {
                    $this->contextStack[$topIdx]['depth']--;
                    $this->emit(TokenKind::T_CLOSE_BRACE, '}', $line, $col, $offset);
                }

                return;
            }
        }

        if ($c === '<' && $this->isJsxStartAllowed() && $this->isIdentStart($this->peek(1))) {
            $this->lexJsxTagOpenStart();

            return;
        }

        if ($c === '$') {
            $this->lexVariable();

            return;
        }
        if ($c === '"' || $c === "'") {
            $this->lexString($c);

            return;
        }
        if (ctype_digit($c)) {
            $this->lexNumber();

            return;
        }
        if ($this->isIdentStart($c)) {
            $this->lexIdentifierOrKeyword();

            return;
        }

        $this->lexOperatorOrPunct();
    }

    private function isJsxStartAllowed(): bool
    {
        if ($this->lastKind === null) {
            return true;
        }
        if (in_array($this->lastKind, self::JSX_ALLOWED_AFTER_KIND, true)) {
            return true;
        }
        if ($this->lastKind === TokenKind::T_OPERATOR) {
            return in_array($this->lastValue, self::JSX_ALLOWED_AFTER_OPERATOR, true);
        }

        return false;
    }

    private function lexVariable(): void
    {
        $line = $this->line;
        $col = $this->col;
        $offset = $this->pos;
        $this->advanceChar(); // '$'
        $name = $this->readIdentifier();
        if ($name === '') {
            throw new LexerException("Nome de variável inválido após '$'", $line, $col);
        }
        $this->emit(TokenKind::T_VARIABLE, $name, $line, $col, $offset);
    }

    private function lexString(string $quote): void
    {
        $line = $this->line;
        $col = $this->col;
        $offset = $this->pos;
        $this->advanceChar(); // aspas de abertura
        $buf = $quote;

        while (!$this->isAtEnd() && $this->peek() !== $quote) {
            if ($this->peek() === '\\' && !$this->isAtEnd(1)) {
                $buf .= $this->advanceChar();
                $buf .= $this->advanceChar();
                continue;
            }
            $buf .= $this->advanceChar();
        }

        if ($this->isAtEnd()) {
            throw new LexerException('String não terminada', $line, $col);
        }
        $buf .= $this->advanceChar(); // aspas de fechamento

        $this->emit(TokenKind::T_CONSTANT_STRING, $buf, $line, $col, $offset);
    }

    private function lexNumber(): void
    {
        $line = $this->line;
        $col = $this->col;
        $offset = $this->pos;
        $buf = '';
        $isFloat = false;

        while (!$this->isAtEnd() && ctype_digit((string) $this->peek())) {
            $buf .= $this->advanceChar();
        }

        if ($this->peek() === '.' && ctype_digit((string) ($this->peek(1) ?? ''))) {
            $isFloat = true;
            $buf .= $this->advanceChar();
            while (!$this->isAtEnd() && ctype_digit((string) $this->peek())) {
                $buf .= $this->advanceChar();
            }
        }

        if ($this->peek() === 'e' || $this->peek() === 'E') {
            $save = $this->pos;
            $saveLine = $this->line;
            $saveCol = $this->col;
            $expBuf = $this->advanceChar();
            if ($this->peek() === '+' || $this->peek() === '-') {
                $expBuf .= $this->advanceChar();
            }
            if (ctype_digit((string) ($this->peek() ?? ''))) {
                $isFloat = true;
                while (!$this->isAtEnd() && ctype_digit((string) $this->peek())) {
                    $expBuf .= $this->advanceChar();
                }
                $buf .= $expBuf;
            } else {
                // não era expoente válido, desfaz o avanço
                $this->pos = $save;
                $this->line = $saveLine;
                $this->col = $saveCol;
            }
        }

        $this->emit($isFloat ? TokenKind::T_DNUMBER : TokenKind::T_LNUMBER, $buf, $line, $col, $offset);
    }

    private function lexIdentifierOrKeyword(): void
    {
        $line = $this->line;
        $col = $this->col;
        $offset = $this->pos;
        $name = $this->readIdentifier();
        $kind = self::KEYWORDS[strtolower($name)] ?? TokenKind::T_IDENTIFIER;
        $this->emit($kind, $name, $line, $col, $offset);
    }

    private function readIdentifier(): string
    {
        $s = '';
        while (!$this->isAtEnd() && $this->isIdentPart($this->peek())) {
            $s .= $this->advanceChar();
        }

        return $s;
    }

    private function lexOperatorOrPunct(): void
    {
        $line = $this->line;
        $col = $this->col;
        $offset = $this->pos;
        $c = $this->peek();

        if (isset(self::PUNCT_SINGLE[$c])) {
            $this->advanceChar();
            $this->emit(self::PUNCT_SINGLE[$c], $c, $line, $col, $offset);

            return;
        }

        $c3 = $this->peekChunk(3);
        if ($c3 !== null && in_array($c3, self::OPS3, true)) {
            $this->advanceChar();
            $this->advanceChar();
            $this->advanceChar();
            $this->emit(TokenKind::T_OPERATOR, $c3, $line, $col, $offset);

            return;
        }

        $c2 = $this->peekChunk(2);
        if ($c2 !== null && in_array($c2, self::OPS2, true)) {
            $this->advanceChar();
            $this->advanceChar();
            $this->emit(TokenKind::T_OPERATOR, $c2, $line, $col, $offset);

            return;
        }

        if ($c !== null && in_array($c, self::OPS1, true)) {
            $this->advanceChar();
            $this->emit(TokenKind::T_OPERATOR, $c, $line, $col, $offset);

            return;
        }

        throw new LexerException("Caractere inesperado '{$c}'", $line, $col);
    }

    private function skipWhitespace(): void
    {
        while (!$this->isAtEnd() && ctype_space((string) $this->peek())) {
            $this->advanceChar();
        }
    }

    private function skipLineComment(): void
    {
        $line = $this->line;
        $col = $this->col;
        $offset = $this->pos;
        $buf = '';
        while (!$this->isAtEnd() && $this->peek() !== "\n") {
            $buf .= $this->advanceChar();
        }
        $this->tokens[] = new Token(TokenKind::T_COMMENT, $buf, $line, $col, $offset);
    }

    private function skipBlockComment(): void
    {
        $line = $this->line;
        $col = $this->col;
        $offset = $this->pos;
        $buf = $this->advanceChar() . $this->advanceChar();
        while (!$this->isAtEnd() && !($this->peek() === '*' && $this->peek(1) === '/')) {
            $buf .= $this->advanceChar();
        }
        if ($this->isAtEnd()) {
            throw new LexerException('Comentário de bloco não terminado', $line, $col);
        }
        $buf .= $this->advanceChar() . $this->advanceChar();
        $this->tokens[] = new Token(TokenKind::T_COMMENT, $buf, $line, $col, $offset);
    }

    // ------------------------------------------------------------------
    // Modo JSX: tag de abertura
    // ------------------------------------------------------------------

    private function lexJsxTagOpenStart(): void
    {
        $line = $this->line;
        $col = $this->col;
        $offset = $this->pos;
        $this->advanceChar(); // '<'
        $name = $this->readTagName();
        $this->emit(TokenKind::T_JSX_TAG_OPEN, $name, $line, $col, $offset);
        $this->contextStack[] = ['type' => 'jsx_open_tag', 'tag' => $name];
    }

    private function readTagName(): string
    {
        $s = '';
        while (!$this->isAtEnd() && ($this->isIdentPart($this->peek()) || $this->peek() === '.')) {
            $s .= $this->advanceChar();
        }

        return $s;
    }

    private function readAttrName(): string
    {
        $s = '';
        while (!$this->isAtEnd() && ($this->isIdentPart($this->peek()) || $this->peek() === '-')) {
            $s .= $this->advanceChar();
        }

        return $s;
    }

    private function lexJsxOpenTagStep(): void
    {
        $this->skipWhitespace();
        if ($this->isAtEnd()) {
            throw new LexerException('Fim de arquivo inesperado dentro de tag JSX aberta', $this->line, $this->col);
        }

        $line = $this->line;
        $col = $this->col;
        $offset = $this->pos;
        $c = $this->peek();

        if ($c === '/' && $this->peek(1) === '>') {
            $this->advanceChar();
            $this->advanceChar();
            array_pop($this->contextStack);
            $this->emit(TokenKind::T_JSX_TAG_SELFCLOSE_END, '/>', $line, $col, $offset);

            return;
        }

        if ($c === '>') {
            $this->advanceChar();
            $tagCtx = array_pop($this->contextStack);
            $this->emit(TokenKind::T_JSX_GT, '>', $line, $col, $offset);
            $this->contextStack[] = ['type' => 'jsx_children', 'tag' => $tagCtx['tag']];

            return;
        }

        if ($this->isIdentStart($c)) {
            $name = $this->readAttrName();
            $this->emit(TokenKind::T_JSX_ATTR_NAME, $name, $line, $col, $offset);
            $this->skipWhitespace();

            if ($this->peek() === '=') {
                $eqLine = $this->line;
                $eqCol = $this->col;
                $eqOffset = $this->pos;
                $this->advanceChar();
                $this->emit(TokenKind::T_JSX_ATTR_EQUALS, '=', $eqLine, $eqCol, $eqOffset);
                $this->skipWhitespace();

                $vc = $this->peek();
                if ($vc === '"' || $vc === "'") {
                    $this->lexString($vc);
                } elseif ($vc === '{') {
                    $sLine = $this->line;
                    $sCol = $this->col;
                    $sOffset = $this->pos;
                    $this->advanceChar();
                    $this->emit(TokenKind::T_JSX_EXPR_START, '{', $sLine, $sCol, $sOffset);
                    $this->contextStack[] = ['type' => 'jsx_expr', 'depth' => 0];
                } else {
                    throw new LexerException("Valor de atributo JSX inválido após '='", $this->line, $this->col);
                }
            }

            return;
        }

        throw new LexerException("Caractere inesperado '{$c}' dentro de tag JSX", $line, $col);
    }

    // ------------------------------------------------------------------
    // Modo JSX: corpo (filhos) de uma tag aberta
    // ------------------------------------------------------------------

    private function lexJsxChildStep(): void
    {
        if ($this->isAtEnd()) {
            throw new LexerException('Fim de arquivo inesperado: tag JSX não fechada', $this->line, $this->col);
        }

        $line = $this->line;
        $col = $this->col;
        $offset = $this->pos;
        $c = $this->peek();

        if ($c === '<') {
            if ($this->peek(1) === '/') {
                $this->advanceChar();
                $this->advanceChar();
                $name = $this->readTagName();
                $this->skipWhitespace();
                if ($this->peek() !== '>') {
                    throw new LexerException("Esperado '>' para fechar tag </{$name}", $this->line, $this->col);
                }
                $this->advanceChar();
                $openCtx = array_pop($this->contextStack);
                if ($openCtx['tag'] !== $name) {
                    throw new LexerException(
                        "Tag de fechamento não corresponde: esperado </{$openCtx['tag']}>, encontrado </{$name}>",
                        $line,
                        $col,
                    );
                }
                $this->emit(TokenKind::T_JSX_TAG_CLOSE, $name, $line, $col, $offset);

                return;
            }

            if ($this->isIdentStart($this->peek(1))) {
                $this->lexJsxTagOpenStart();

                return;
            }

            throw new LexerException("'<' inesperado dentro do conteúdo JSX", $line, $col);
        }

        if ($c === '{') {
            $this->advanceChar();
            $this->emit(TokenKind::T_JSX_EXPR_START, '{', $line, $col, $offset);
            $this->contextStack[] = ['type' => 'jsx_expr', 'depth' => 0];

            return;
        }

        $text = '';
        while (!$this->isAtEnd() && $this->peek() !== '<' && $this->peek() !== '{') {
            $text .= $this->advanceChar();
        }
        if ($text !== '') {
            $this->emit(TokenKind::T_JSX_TEXT, $text, $line, $col, $offset);
        }
    }
}