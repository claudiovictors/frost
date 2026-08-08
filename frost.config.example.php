<?php

declare(strict_types=1);

/**
 * Copie este arquivo pra frost.config.php na raiz do seu APP CONSUMIDOR
 * (não deste repositório do framework) e ajuste os caminhos.
 */
return [
    // Pasta com os arquivos .php em sintaxe Frost.
    'source' => __DIR__ . '/app',

    // Arquivo de entrada dentro de 'source'. O nome do arquivo (sem .php)
    // precisa bater com o nome da função que vira o componente raiz
    // exportado: App.php -> function App() {...} -> export default App;
    'entry' => 'App.php',

    // Pasta src/generated/ do seu projeto RN (ver pacote Runtime/Bridge,
    // stubs/rn-bridge-template/src/generated/).
    'output' => __DIR__ . '/mobile/src/generated',
];