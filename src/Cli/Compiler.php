<?php

declare(strict_types=1);

namespace Frost\Cli;

use Frost\Ast\Stmt\FunctionDecl;
use Frost\Ast\Stmt\UseStmt;
use Frost\Components\ImportInjector;
use Frost\Lexer\Lexer;
use Frost\Parser\Parser;
use Frost\Transpiler\Transpiler;

/**
 * Compila arquivos .php (sintaxe Frost) em JS/JSX pronto pra src/generated/
 * do projeto RN (ver pacote Runtime/Bridge).
 *
 * compileFile() compila UM arquivo isolado (usado internamente e por quem
 * só quer testar um trecho). compileTree() é o que o CLI usa de verdade:
 * compila o arquivo de entrada e, recursivamente, todo componente
 * referenciado via `use App\Componentes\Header;` — resolvido pela convenção
 * "o último segmento do namespace é o nome do arquivo .php", procurado na
 * mesma pasta 'source' do frost.config.php.
 *
 * Débito consciente: não é resolução de namespace/PSR-4 de verdade, é só
 * convenção de nome de arquivo. Também não detecta import circular de forma
 * elegante — só evita loop infinito (ver $compiled como guarda).
 */
final class Compiler
{
    public static function compileFile(string $phpPath): string
    {
        $ast = self::parseFile($phpPath);

        $useImports = self::useImportLines($ast);
        $rnImports = ImportInjector::generateImports($ast);
        $js = (new Transpiler())->transpileProgram($ast);
        $footer = self::exportFooter($phpPath, $ast);

        return $useImports . $rnImports . $js . $footer;
    }

    /**
     * Compila o arquivo de entrada e toda a árvore de componentes que ele
     * referencia via `use`. Retorna [nomeDoArquivoSemExtensao => jsString].
     *
     * @return array<string, string>
     */
    public static function compileTree(string $entryPath, string $sourceDir): array
    {
        $compiled = [];
        self::compileRecursive($entryPath, rtrim($sourceDir, '/'), $compiled);

        return $compiled;
    }

    /** @param array<string, string|null> $compiled */
    private static function compileRecursive(string $phpPath, string $sourceDir, array &$compiled): void
    {
        $name = pathinfo($phpPath, PATHINFO_FILENAME);

        // já compilado (ou em progresso — corta ciclo de imports) -> não repete
        if (array_key_exists($name, $compiled)) {
            return;
        }
        $compiled[$name] = null; // marca "em progresso" antes de recursar

        if (!is_file($phpPath)) {
            throw new \RuntimeException("Componente referenciado não encontrado: {$phpPath}");
        }

        $ast = self::parseFile($phpPath);

        foreach (self::useTargets($ast) as $target) {
            self::compileRecursive($sourceDir . '/' . $target . '.php', $sourceDir, $compiled);
        }

        $useImports = self::useImportLines($ast);
        $rnImports = ImportInjector::generateImports($ast);
        $js = (new Transpiler())->transpileProgram($ast);
        $footer = self::exportFooter($phpPath, $ast);

        $compiled[$name] = $useImports . $rnImports . $js . $footer;
    }

    /** @return \Frost\Ast\Node[] */
    private static function parseFile(string $phpPath): array
    {
        $source = file_get_contents($phpPath);
        if ($source === false) {
            throw new \RuntimeException("Não consegui ler o arquivo: {$phpPath}");
        }

        $tokens = (new Lexer($source))->tokenize();

        return (new Parser($tokens))->parseProgram();
    }

    /** @param \Frost\Ast\Node[] $ast */
    private static function useImportLines(array $ast): string
    {
        $lines = '';
        foreach ($ast as $stmt) {
            if ($stmt instanceof UseStmt) {
                $lines .= "import {$stmt->localName()} from './{$stmt->fileName()}';\n";
            }
        }

        return $lines !== '' ? $lines . "\n" : '';
    }

    /**
     * @param \Frost\Ast\Node[] $ast
     * @return string[] nomes de arquivo (sem .php) referenciados via `use`
     */
    private static function useTargets(array $ast): array
    {
        $targets = [];
        foreach ($ast as $stmt) {
            if ($stmt instanceof UseStmt) {
                $targets[] = $stmt->fileName();
            }
        }

        return $targets;
    }

    /**
     * Decide o `export default` por convenção: o nome do arquivo (sem .php)
     * precisa bater com o nome de uma função declarada nele. Se não achar,
     * não emite export nenhum e quem chamou avisa isso no terminal.
     *
     * @param \Frost\Ast\Node[] $ast
     */
    private static function exportFooter(string $phpPath, array $ast): string
    {
        $expectedName = pathinfo($phpPath, PATHINFO_FILENAME);

        foreach ($ast as $stmt) {
            if ($stmt instanceof FunctionDecl && $stmt->name === $expectedName) {
                return "\nexport default {$expectedName};\n";
            }
        }

        return '';
    }
}