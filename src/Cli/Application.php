<?php

declare(strict_types=1);

namespace Frost\Cli;

final class Application
{
    /** @param string[] $argv */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? null;
        $rest = array_slice($argv, 2);

        return match ($command) {
            'build' => (new BuildCommand())->run($rest),
            'dev' => (new DevCommand())->run($rest),
            null, 'help', '--help', '-h' => $this->printHelp(),
            default => $this->unknownCommand((string) $command),
        };
    }

    private function printHelp(): int
    {
        fwrite(STDOUT, <<<TXT
        Frost CLI

        Uso:
          frost build [--config=caminho/frost.config.php]   Compila uma vez
          frost dev   [--config=caminho/frost.config.php]   Compila e observa mudanças (rebuild completo)
          frost help                                        Mostra esta ajuda

        Sem --config, procura frost.config.php no diretório atual.

        TXT);

        return 0;
    }

    private function unknownCommand(string $command): int
    {
        fwrite(STDERR, "Comando desconhecido: '{$command}'\n\n");
        $this->printHelp();

        return 1;
    }
}