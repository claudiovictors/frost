# Frost

Framework PHP que permite escrever apps mobile reais (iOS/Android) usando
sintaxe PHP com componentes estilo JSX, transpilando pra React Native.

```php
<?php

function App() {
    $count = useState(0);
    $styles = StyleSheet::create([
        'container' => ['flex' => 1, 'alignItems' => 'center', 'justifyContent' => 'center'],
    ]);

    return (
        <View style={$styles->container}>
            <Text>Você clicou {$count} vezes</Text>
            <Button title="Clicar" onPress={fn() => setCount($count + 1)} />
        </View>
    );
}
```

Isso vira JSX/JS de verdade, compilado pelo Metro e rodando com Hermes/JSC
igual qualquer app React Native.

## Pipeline

```
código PHP (.php, sintaxe JSX-like)
   → Lexer      (tokeniza PHP + tags JSX-like)
   → Parser     (tokens → AST)
   → Transpiler (AST → string JS/JSX válida)
   → ImportInjector (auto-import de componentes RN e hooks usados)
   → CLI (`frost build` / `frost dev`)
   → bundler do React Native (Metro) + Babel
   → engine JS (Hermes/JSC) + bridge nativo → app iOS/Android
```

## Instalação

O Frost é o **framework**. O app que você constrói com ele é um projeto
separado (ver seção "Criando um app" abaixo).

```bash
composer require frost/framework
```

## O que já funciona

- Function components com JSX aninhado (qualquer componente do React Native)
- `useState` — vira destructuring `[x, setX]` automaticamente
- `StyleSheet::create([...])`
- `if` / `elseif` / `else`
- `foreach ($x as $v)` e `foreach ($x as $k => $v)`
- Múltiplos componentes em arquivos separados, importando um no outro
  (`use App\Componentes\Header;`)
- `async function`, `await`, `try` / `catch` / `finally` — chamadas de API
- Auto-import de componentes React Native e hooks usados no arquivo

## O que ainda não existe (v0.1)

- Classes, `for`/`while`/`switch`/`match`
- Navegação entre telas (React Navigation)
- Context/Providers
- Resolução de namespace/PSR-4 de verdade pro `use` (hoje é convenção de
  nome de arquivo — o último segmento do namespace precisa bater com o
  nome do `.php`)

## Criando um app

O framework e o app consumidor vivem em projetos/pastas **separados**.

1. Cria um projeto React Native de verdade (o Frost não gera as pastas
   nativas `android/`/`ios/` — ver `stubs/rn-bridge-template/README.md`
   pra entender por quê):
   ```bash
   npx create-expo-app MeuApp --template blank
   # ou: npx @react-native-community/cli init MeuApp
   ```

2. Copia os arquivos de `stubs/rn-bridge-template/` (do pacote Frost
   instalado via Composer, em `vendor/frost/framework/stubs/rn-bridge-template/`)
   por cima do seu projeto RN — são o `App.js` de ponte, config do Babel/Metro
   e a pasta `src/generated/` onde o Frost escreve o output.

3. Cria seu código-fonte `.php` numa pasta separada (ex: `app/App.php`) e um
   `frost.config.php` apontando pra ela (ver `frost.config.example.php`
   neste repositório).

4. Compila e observa mudanças:
   ```bash
   vendor/bin/frost build   # compila uma vez
   vendor/bin/frost dev     # observa e recompila automaticamente
   ```

5. Roda o app normalmente (`npx expo start` ou `npx react-native run-android`).

## Estrutura do framework

```
src/
├── Lexer/        tokeniza PHP + tags JSX-like
├── Parser/       tokens → AST (recursive-descent, escrito à mão)
├── Ast/          nós de AST: statements, JSX
├── Expr/         nós de AST: expressões
├── Transpiler/   AST → string JS/JSX
├── Components/   registro de componentes RN/hooks, auto-import
└── Cli/          comandos `build` e `dev`
stubs/
└── rn-bridge-template/   projeto RN mínimo que roda o output do Frost
```

## Licença

MIT