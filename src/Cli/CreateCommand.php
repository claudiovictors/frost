<?php

declare(strict_types=1);

namespace Frost\Cli;

/**
 * `frost create <nome>` — cria o projeto inteiro numa tacada só: o código
 * PHP (com uma tela de boas-vindas de verdade, logo incluído, estilos em
 * arquivo separado), o projeto React Native via Expo, conecta os dois, e
 * já deixa instalado. Espelha o `laravel new` / `npm create vite`.
 *
 * Nota importante sobre distribuição: isso só funciona se o binário
 * `frost` já estiver acessível (via `composer global require
 * claudiovictors/frost`, igual o `laravel/installer`) — um pacote comum
 * instalado via `composer require` dentro de um projeto não tem como se
 * auto-executar antes de existir. Documentado no README.
 *
 * Débito consciente: shell out pra `npx create-expo-app` (via `passthru`).
 * Se o Node/npx não estiver disponível ou a versão do Expo mudar o
 * comportamento do CLI deles, esse passo pode falhar — nesse caso, os
 * arquivos PHP já foram criados com sucesso, só a parte RN que não.
 */
final class CreateCommand
{
    /** @param string[] $args */
    public function run(array $args): int
    {
        $name = $args[0] ?? null;
        if ($name === null || str_starts_with($name, '--')) {
            fwrite(STDERR, "Uso: frost create <nome-do-projeto>\n");

            return 1;
        }

        $root = rtrim(getcwd(), '/') . '/' . $name;
        $srcDir = $root . '/src';
        $appDir = $srcDir . '/app';
        $mobileDir = $root . '/mobile';

        if (is_dir($root)) {
            fwrite(STDERR, "✗ A pasta '{$name}' já existe.\n");

            return 1;
        }

        fwrite(STDOUT, "Criando projeto Frost '{$name}'...\n\n");

        mkdir($appDir, 0777, true);

        file_put_contents($appDir . '/App.php', self::appTemplate());
        file_put_contents($appDir . '/AppStyles.php', self::appStylesTemplate());
        file_put_contents($appDir . '/Logo.php', self::logoTemplate());
        file_put_contents($appDir . '/LogoStyles.php', self::logoStylesTemplate());
        file_put_contents($srcDir . '/frost.config.php', self::configTemplate($mobileDir));
        file_put_contents($srcDir . '/composer.json', self::composerTemplate());

        fwrite(STDOUT, "✓ Código-fonte Frost criado em {$srcDir}\n");

        fwrite(STDOUT, "\nInstalando o framework via Composer...\n");
        $this->runShell('cd ' . escapeshellarg($srcDir) . ' && composer require claudiovictors/frost 2>&1');

        fwrite(STDOUT, "\nGerando projeto React Native (Expo) — isso pode demorar um pouco...\n");
        $expoOk = $this->runShell(
            'cd ' . escapeshellarg($root) . ' && npx --yes create-expo-app mobile --template blank 2>&1',
        );

        if (!$expoOk) {
            fwrite(STDERR, "\n✗ Falha ao gerar o projeto Expo (verifica se node/npx estão instalados).\n");
            fwrite(STDERR, "  O código PHP já foi criado em {$srcDir} — você pode gerar o projeto RN manualmente depois.\n");

            return 1;
        }

        $this->wireBridgeFiles($mobileDir);

        fwrite(STDOUT, "\nCompilando o primeiro build...\n");
        $this->runShell('cd ' . escapeshellarg($srcDir) . ' && vendor/bin/frost build 2>&1');

        fwrite(STDOUT, "\n✓ Tudo pronto! Pra rodar:\n\n");
        fwrite(STDOUT, "  cd {$name}/src && vendor/bin/frost dev      (num terminal)\n");
        fwrite(STDOUT, "  cd {$name}/mobile && npx expo start          (noutro terminal, aperta 'w' ou escaneia o QR code)\n\n");

        return 0;
    }

    private function runShell(string $command): bool
    {
        passthru($command, $exitCode);

        return $exitCode === 0;
    }

    /**
     * Pluga o App.js de ponte no projeto Expo recém-criado. NÃO mexe em
     * babel.config.js nem metro.config.js — o Expo já gera os corretos
     * sozinho (usam 'expo/metro-config' e 'babel-preset-expo'). O antigo
     * stubs/rn-bridge-template foi escrito pensando num projeto RN "puro"
     * via @react-native-community/cli (que usa '@react-native/metro-
     * config', um pacote diferente) — sobrescrever com aquele aqui quebra
     * o Metro do Expo com "Cannot find module '@react-native/metro-config'".
     */
    private function wireBridgeFiles(string $mobileDir): void
    {
        @mkdir($mobileDir . '/src/generated', 0777, true);

        file_put_contents($mobileDir . '/App.js', "import App from './src/generated/App';\n\nexport default App;\n");
        file_put_contents($mobileDir . '/src/generated/.gitignore', "*\n!.gitignore\n");

        fwrite(STDOUT, "✓ Projeto React Native conectado ao Frost em {$mobileDir}\n");
    }

    private static function configTemplate(string $mobileDir): string
    {
        $mobileDirEscaped = addslashes($mobileDir);

        return <<<CFG
<?php

declare(strict_types=1);

return [
    'source' => __DIR__ . '/app',
    'entry' => 'App.php',
    'output' => '{$mobileDirEscaped}/src/generated',
];

CFG;
    }

    private static function composerTemplate(): string
    {
        return <<<'JSON'
{
    "require": {
        "php": ">=8.1"
    }
}
JSON;
    }

    private static function appTemplate(): string
    {
        return <<<'FROST'
<?php

use App\Logo;
use App\AppStyles;

function App() {
    $name = useState('');
    $greeting = useState('');
    $styles = AppStyles();

    return (
        <View style={$styles->page}>
            <View style={$styles->card}>
                <Logo />
                <Text style={$styles->title}>Bem-vindo ao Frost</Text>
                <Text style={$styles->subtitle}>PHP com sintaxe JSX, rodando de verdade no React Native.</Text>
                <View style={$styles->row}>
                    <TextInput
                        style={$styles->input}
                        placeholder="Seu nome"
                        value={$name}
                        onChangeText={fn($text) => setName($text)}
                    />
                    <Button title="Cumprimentar" onPress={fn() => setGreeting("Olá, " . $name . "! Bem-vindo ao Frost.")} />
                </View>
                {$greeting ? <Text style={$styles->greeting}>{$greeting}</Text> : null}
            </View>
        </View>
    );
}
FROST;
    }

    private static function appStylesTemplate(): string
    {
        return <<<'FROST'
<?php

function AppStyles() {
    return StyleSheet::create([
        'page' => [
            'flex' => 1,
            'backgroundColor' => '#0f172a',
            'alignItems' => 'center',
            'justifyContent' => 'center',
            'padding' => 24,
        ],
        'card' => [
            'width' => '100%',
            'maxWidth' => 360,
            'backgroundColor' => '#ffffff',
            'borderRadius' => 20,
            'padding' => 28,
            'alignItems' => 'center',
        ],
        'title' => [
            'fontSize' => 22,
            'fontWeight' => 'bold',
            'color' => '#0f172a',
            'marginBottom' => 8,
            'textAlign' => 'center',
        ],
        'subtitle' => [
            'fontSize' => 14,
            'color' => '#64748b',
            'textAlign' => 'center',
            'marginBottom' => 24,
        ],
        'row' => [
            'flexDirection' => 'row',
            'width' => '100%',
            'gap' => 8,
            'marginBottom' => 12,
        ],
        'input' => [
            'flex' => 1,
            'borderWidth' => 1,
            'borderColor' => '#e2e8f0',
            'borderRadius' => 10,
            'paddingHorizontal' => 12,
            'paddingVertical' => 8,
            'fontSize' => 14,
        ],
        'greeting' => [
            'marginTop' => 12,
            'fontSize' => 15,
            'color' => '#2563eb',
            'textAlign' => 'center',
        ],
    ]);
}
FROST;
    }

    private static function logoTemplate(): string
    {
        return <<<'FROST'
<?php

use App\LogoStyles;

function Logo() {
    $styles = LogoStyles();

    return (
        <View style={$styles->wrap}>
            <View style={$styles->bar1} />
            <View style={$styles->bar2} />
            <View style={$styles->bar3} />
            <View style={$styles->center} />
        </View>
    );
}
FROST;
    }

    private static function logoStylesTemplate(): string
    {
        return <<<'FROST'
<?php

function LogoStyles() {
    return StyleSheet::create([
        'wrap' => [
            'width' => 96,
            'height' => 96,
            'alignItems' => 'center',
            'justifyContent' => 'center',
            'marginBottom' => 20,
        ],
        'bar1' => [
            'position' => 'absolute',
            'width' => 90,
            'height' => 10,
            'borderRadius' => 5,
            'backgroundColor' => '#2563eb',
        ],
        'bar2' => [
            'position' => 'absolute',
            'width' => 90,
            'height' => 10,
            'borderRadius' => 5,
            'backgroundColor' => '#3b82f6',
            'transform' => [['rotate' => '60deg']],
        ],
        'bar3' => [
            'position' => 'absolute',
            'width' => 90,
            'height' => 10,
            'borderRadius' => 5,
            'backgroundColor' => '#60a5fa',
            'transform' => [['rotate' => '120deg']],
        ],
        'center' => [
            'position' => 'absolute',
            'width' => 16,
            'height' => 16,
            'borderRadius' => 8,
            'backgroundColor' => '#1d4ed8',
        ],
    ]);
}
FROST;
    }
}