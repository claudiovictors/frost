<?php

declare(strict_types=1);

namespace Frost\Cli;

/**
 * Watch mode v1 (decisão já tomada): rebuild completo a cada mudança, sem
 * incremental. Implementado com polling de mtime — sem depender da extensão
 * inotify do PHP (que pode não estar instalada).
 *
 * Observa TODOS os .php da pasta 'source' (não só o entry), porque agora um
 * componente importado via `use` pode mudar sozinho e precisa disparar
 * rebuild da árvore inteira também.
 */
final class DevCommand
{
    private const POLL_INTERVAL_US = 400_000; // 0.4s

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

        $sourceDir = rtrim($config['source'], '/');
        fwrite(STDOUT, "Frost dev — observando {$sourceDir}/*.php (Ctrl+C pra sair)\n");

        $build = new BuildCommand();
        $lastSignature = null;

        while (true) {
            $signature = $this->sourceSignature($sourceDir);

            if ($signature !== $lastSignature) {
                $lastSignature = $signature;
                $build->build($config); // erros já são tratados e impressos lá dentro; o loop continua de qualquer jeito
            }

            usleep(self::POLL_INTERVAL_US);
        }
    }

    /** Assinatura barata do estado da pasta: nome+mtime de cada .php, concatenados. */
    private function sourceSignature(string $sourceDir): string
    {
        $files = glob($sourceDir . '/*.php') ?: [];
        sort($files);

        $parts = [];
        foreach ($files as $file) {
            clearstatcache(true, $file);
            $parts[] = $file . ':' . filemtime($file);
        }

        return implode('|', $parts);
    }
}