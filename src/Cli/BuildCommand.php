<?php

declare(strict_types=1);

namespace Frost\Cli;

final class BuildCommand
{
    /** @param string[] $args */
    public function run(array $args): int
    {
        $configPath = Config::extractConfigOption($args) ?? getcwd() . '/frost.config.php';

        try {
            $config = Config::load($configPath);
        } catch (\Throwable $e) {
            fwrite(STDERR, "✗ {$e->getMessage()}\n");

            return 1;
        }

        return $this->build($config);
    }

    /** @param array{source: string, entry: string, output: string} $config */
    public function build(array $config): int
    {
        $sourceDir = rtrim($config['source'], '/');
        $entryPath = $sourceDir . '/' . $config['entry'];

        if (!is_file($entryPath)) {
            fwrite(STDERR, "✗ Arquivo de entrada não encontrado: {$entryPath}\n");

            return 1;
        }

        $start = microtime(true);

        try {
            $filesJs = Compiler::compileTree($entryPath, $sourceDir);
        } catch (\Throwable $e) {
            fwrite(STDERR, '✗ erro de build: ' . $e->getMessage() . "\n");

            return 1;
        }

        $outDir = rtrim($config['output'], '/');
        if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
            fwrite(STDERR, "✗ não consegui criar a pasta de saída: {$outDir}\n");

            return 1;
        }

        $entryName = pathinfo($config['entry'], PATHINFO_FILENAME);
        foreach ($filesJs as $name => $js) {
            file_put_contents($outDir . '/' . $name . '.js', $js);
        }

        $elapsedMs = (int) round((microtime(true) - $start) * 1000);
        $entryJs = $filesJs[$entryName] ?? '';
        $warning = str_contains($entryJs, 'export default') ? '' : ' (aviso: entrada sem export default — nenhuma função nela tem o mesmo nome do arquivo)';
        $count = count($filesJs);
        $plural = $count === 1 ? 'arquivo' : 'arquivos';
        fwrite(STDOUT, "✓ build ok -> {$outDir}/ ({$count} {$plural}, {$elapsedMs}ms){$warning}\n");

        return 0;
    }
}