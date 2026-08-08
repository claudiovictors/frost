<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Frost\Lexer\Lexer;
use Frost\Lexer\TokenKind;

$source = <<<'FROST'
<?php

function App() {
    $count = useState(0);
    $label = $count > 0 ? "positivo" : 'zero';

    return (
        <View style={styles.container}>
            <Text>Contagem: {$count}</Text>
            <Button title="Incrementar" onPress={fn() => setCount($count + 1)} />
        </View>
    );
}
FROST;

$tokens = (new Lexer($source))->tokenize();

echo "=== TOKENS ===\n";
foreach ($tokens as $t) {
    echo $t, "\n";
}

// ------------------------------------------------------------------
// Checagens simples (sem framework de testes, só pra pegar regressão óbvia)
// ------------------------------------------------------------------

function check(string $label, bool $condition): void
{
    echo ($condition ? "[OK]   " : "[FAIL] ") . $label . "\n";
    if (!$condition) {
        exit(1);
    }
}

$byKind = static function (array $tokens, TokenKind $kind): array {
    return array_values(array_filter($tokens, static fn ($t) => $t->kind === $kind));
};

$opens = $byKind($tokens, TokenKind::T_JSX_TAG_OPEN);
$closes = $byKind($tokens, TokenKind::T_JSX_TAG_CLOSE);
$selfClose = $byKind($tokens, TokenKind::T_JSX_TAG_SELFCLOSE_END);
$exprStart = $byKind($tokens, TokenKind::T_JSX_EXPR_START);
$exprEnd = $byKind($tokens, TokenKind::T_JSX_EXPR_END);
$text = $byKind($tokens, TokenKind::T_JSX_TEXT);

check('3 tags abertas (View, Text, Button)', count($opens) === 3);
check('nomes das tags abertas corretos', implode(',', array_map(fn ($t) => $t->value, $opens)) === 'View,Text,Button');
check('2 tags fechadas com </...> (View, Text)', count($closes) === 2);
check('1 tag self-closing (Button)', count($selfClose) === 1);
check('expr_start e expr_end balanceados', count($exprStart) === count($exprEnd));
check('4 expressões JSX ({styles.container}, {$count} filho, {fn()=>...}, e a do array de attrs)', count($exprStart) === 3);
$hasContagemText = array_filter($text, static fn ($t) => trim($t->value) === 'Contagem:');
check('texto "Contagem: " capturado como T_JSX_TEXT', count($hasContagemText) === 1);
check('último token é EOF', end($tokens)->kind === TokenKind::T_EOF);

echo "\nTodas as checagens passaram.\n";