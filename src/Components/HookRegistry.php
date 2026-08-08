<?php

declare(strict_types=1);

namespace Frost\Components;

/**
 * Registro de hooks do React reconhecidos pelo pipeline. Hoje só `useState`
 * recebe reescrita especial no Transpiler (destructuring [x, setX]); os
 * demais listados aqui só entram no auto-import — chamá-los funciona como
 * uma chamada de função comum, sem tradução extra (débito: useEffect com
 * array de dependências, useMemo/useCallback com deps, etc. passam direto).
 */
final class HookRegistry
{
    private const REACT_HOOKS = [
        'useState',
        'useEffect',
        'useMemo',
        'useCallback',
        'useRef',
        'useContext',
    ];

    public static function isHook(string $name): bool
    {
        return in_array($name, self::REACT_HOOKS, true);
    }

    /** @return string[] */
    public static function knownHooks(): array
    {
        return self::REACT_HOOKS;
    }
}