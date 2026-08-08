<?php

declare(strict_types=1);

namespace Frost\Lexer;

/**
 * Tipos de token produzidos pelo Lexer do Frost.
 *
 * Convenção adotada (decisão consciente de escopo):
 * - Pontuação estrutural (parênteses, chaves, colchetes, ; ,) tem kind próprio,
 *   porque o Parser precisa casar esses pares o tempo todo e fica mais legível.
 * - Operadores (aritméticos, lógicos, comparação, atribuição, ->, =>, ::, etc.)
 *   são todos T_OPERATOR, diferenciados pelo `value` do Token. Evita explodir
 *   o enum em 40+ variantes pra algo que o Parser vai comparar por valor mesmo.
 */
enum TokenKind
{
    // Estrutura / fim de arquivo
    case T_EOF;
    case T_COMMENT;

    // Literais e identificadores
    case T_VARIABLE;        // $nome
    case T_LNUMBER;         // inteiro
    case T_DNUMBER;         // float
    case T_CONSTANT_STRING; // string entre aspas (valor inclui as aspas, ver debt no Lexer)
    case T_IDENTIFIER;      // identificador que não é palavra reservada

    // Palavras-chave
    case T_FUNCTION;
    case T_RETURN;
    case T_IF;
    case T_ELSE;
    case T_ELSEIF;
    case T_FOREACH;
    case T_FOR;
    case T_WHILE;
    case T_DO;
    case T_CLASS;
    case T_EXTENDS;
    case T_IMPLEMENTS;
    case T_INTERFACE;
    case T_TRAIT;
    case T_ABSTRACT;
    case T_FINAL;
    case T_READONLY;
    case T_PUBLIC;
    case T_PRIVATE;
    case T_PROTECTED;
    case T_STATIC;
    case T_CONST;
    case T_NEW;
    case T_USE;
    case T_NAMESPACE;
    case T_ECHO;
    case T_PRINT;
    case T_TRUE;
    case T_FALSE;
    case T_NULL;
    case T_ARRAY;
    case T_FN;
    case T_MATCH;
    case T_BREAK;
    case T_CONTINUE;
    case T_SWITCH;
    case T_CASE;
    case T_DEFAULT;
    case T_TRY;
    case T_CATCH;
    case T_FINALLY;
    case T_THROW;
    case T_GLOBAL;
    case T_INSTANCEOF;
    case T_ENUM;
    case T_YIELD;
    case T_AND;
    case T_OR;
    case T_AS;
    case T_ASYNC;
    case T_AWAIT;

    // Pontuação estrutural
    case T_OPEN_PAREN;
    case T_CLOSE_PAREN;
    case T_OPEN_BRACE;
    case T_CLOSE_BRACE;
    case T_OPEN_BRACKET;
    case T_CLOSE_BRACKET;
    case T_SEMICOLON;
    case T_COMMA;

    // Operadores genéricos (diferenciados por Token::$value)
    case T_OPERATOR;

    // JSX
    case T_JSX_TAG_OPEN;          // valor = nome da tag, ex: "View"
    case T_JSX_TAG_CLOSE;         // valor = nome da tag, ex: "View"
    case T_JSX_GT;                // '>' que fecha a tag de abertura
    case T_JSX_TAG_SELFCLOSE_END; // '/>'
    case T_JSX_ATTR_NAME;         // valor = nome do atributo
    case T_JSX_ATTR_EQUALS;       // '='
    case T_JSX_EXPR_START;        // '{' (início de expressão em atributo ou filho)
    case T_JSX_EXPR_END;          // '}' correspondente
    case T_JSX_TEXT;              // texto literal entre tags
}