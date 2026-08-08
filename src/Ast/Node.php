<?php

declare(strict_types=1);

namespace Frost\Ast;

/**
 * Marcador comum pra todo nó da AST do Frost.
 *
 * Nota de design (ajuste sobre a decisão anterior): a AST NÃO instancia as
 * classes Node do nikic/php-parser diretamente. Sem acesso à internet neste
 * ambiente de sandbox pra instalar o pacote e validar as assinaturas exatas
 * dos construtores de cada versão, prefiro não arriscar acoplar a um formato
 * que eu não consigo testar de verdade agora. Em vez disso, o Frost define
 * sua própria AST enxuta e 100% testável hoje. O nikic continua como
 * dependência declarada (útil futuramente, ex: se quisermos re-parsear um
 * arquivo .php "normal" importado), mas o pipeline Lexer->Parser->Transpiler
 * não depende dele.
 */
interface Node
{
}