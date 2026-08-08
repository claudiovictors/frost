<?php

declare(strict_types=1);

namespace Frost\Cli;

/**
 * `frost init` — scaffold inicial do projeto. Cria app/App.php (com uma
 * tela de boas-vindas de verdade, não um placeholder vazio) e
 * frost.config.php, se ainda não existirem. Nunca sobrescreve arquivo que
 * já existe — só preenche o que falta.
 */
final class InitCommand
{
    /** @param string[] $args */
    public function run(array $args): int
    {
        $target = rtrim(getcwd(), '/');
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--path=')) {
                $target = rtrim(substr($arg, strlen('--path=')), '/');
            }
        }

        $appDir = $target . '/app';
        $appPath = $appDir . '/App.php';
        $configPath = $target . '/frost.config.php';

        if (!is_dir($appDir) && !mkdir($appDir, 0777, true) && !is_dir($appDir)) {
            fwrite(STDERR, "✗ Não consegui criar a pasta: {$appDir}\n");

            return 1;
        }

        $created = [];

        if (!is_file($appPath)) {
            file_put_contents($appPath, self::welcomeTemplate());
            $created[] = $appPath;
        }

        if (!is_file($configPath)) {
            file_put_contents($configPath, self::configTemplate());
            $created[] = $configPath;
        }

        if ($created === []) {
            fwrite(STDOUT, "Nada a criar — app/App.php e frost.config.php já existem.\n");

            return 0;
        }

        fwrite(STDOUT, "✓ Projeto Frost iniciado:\n");
        foreach ($created as $file) {
            fwrite(STDOUT, "  - {$file}\n");
        }
        fwrite(STDOUT, "\nAjusta o campo 'output' em frost.config.php pra apontar pro seu projeto React Native.\n");
        fwrite(STDOUT, "Depois: vendor/bin/frost dev\n");

        return 0;
    }

    private static function welcomeTemplate(): string
    {
        return <<<'FROST'
<?php

function App() {
    $count = useState(0);
    $styles = StyleSheet::create([
        'container' => [
            'flex' => 1,
            'alignItems' => 'center',
            'justifyContent' => 'center',
            'padding' => 24,
            'backgroundColor' => '#0f172a',
        ],
        'title' => [
            'fontSize' => 28,
            'fontWeight' => 'bold',
            'color' => '#f8fafc',
            'marginBottom' => 8,
        ],
        'subtitle' => [
            'fontSize' => 15,
            'color' => '#94a3b8',
            'marginBottom' => 24,
            'textAlign' => 'center',
        ],
        'counterBox' => [
            'backgroundColor' => '#1e293b',
            'borderRadius' => 12,
            'padding' => 20,
            'marginBottom' => 20,
        ],
        'counterText' => [
            'fontSize' => 18,
            'color' => '#f8fafc',
        ],
    ]);

    return (
        <View style={$styles->container}>
            <Text style={$styles->title}>Frost</Text>
            <Text style={$styles->subtitle}>
                Seu primeiro app rodando. Edite app/App.php e salve
                o "frost dev" recompila sozinho.
            </Text>
            <View style={$styles->counterBox}>
                <Text style={$styles->counterText}>Você clicou {$count} vezes</Text>
            </View>
            <Button title="Clicar aqui" onPress={fn() => setCount($count + 1)} />
        </View>
    );
}
FROST;
    }

    private static function configTemplate(): string
    {
        return <<<'CFG'
<?php

declare(strict_types=1);

return [
    // Pasta com os arquivos .php em sintaxe Frost.
    'source' => __DIR__ . '/app',

    // Arquivo de entrada dentro de 'source'. O nome do arquivo (sem .php)
    // precisa bater com o nome da função que vira o componente raiz
    // exportado: App.php -> function App() {...} -> export default App;
    'entry' => 'App.php',

    // Pasta src/generated/ do seu projeto React Native. AJUSTA ESSE CAMINHO.
    'output' => __DIR__ . '/../MeuAppExpo/src/generated',
];
CFG;
    }
}