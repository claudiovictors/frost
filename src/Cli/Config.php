<?php

declare(strict_types=1);

namespace Frost\Cli;

/**
 * frost.config.php vive na raiz do APP CONSUMIDOR (não deste framework —
 * ver decisão já tomada: framework e app vivem em repos/pastas separados).
 * Formato esperado: um array PHP com as chaves 'source', 'entry', 'output'.
 * Ver frost.config.example.php na raiz do framework.
 */
final class Config
{
    /** @return array{source: string, entry: string, output: string} */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(
                "Config não encontrado: {$path}\n"
                . "Crie um frost.config.php na raiz do seu app (ver frost.config.example.php neste framework).",
            );
        }

        $config = require $path;

        if (!is_array($config)) {
            throw new \RuntimeException("{$path} precisa retornar um array.");
        }

        foreach (['source', 'entry', 'output'] as $key) {
            if (!isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '') {
                throw new \RuntimeException("Config inválido em {$path}: chave '{$key}' (string) é obrigatória.");
            }
        }

        return $config;
    }

    /** Extrai --config=caminho dos argumentos de linha de comando, se presente. */
    public static function extractConfigOption(array $args): ?string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--config=')) {
                return substr($arg, strlen('--config='));
            }
        }

        return null;
    }
}