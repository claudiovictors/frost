<?php

declare(strict_types=1);

namespace Frost\Components;

/**
 * Registro de "quem é componente nativo do RN" — usado pelo ImportInjector
 * pra decidir quais tags JSX (e nomes usados via `::`, como StyleSheet)
 * ganham import automático de 'react-native'.
 *
 * Componentes desconhecidos deste registro (ex: um <Icon /> de uma lib
 * externa, ou um componente próprio do app) NÃO recebem import automático —
 * o dev importa manualmente, ou registra aqui se for algo comum no projeto.
 */
final class ComponentRegistry
{
    private const REACT_NATIVE_COMPONENTS = [
        'View',
        'Text',
        'Image',
        'ScrollView',
        'TextInput',
        'TouchableOpacity',
        'TouchableHighlight',
        'TouchableWithoutFeedback',
        'Button',
        'SafeAreaView',
        'FlatList',
        'SectionList',
        'Modal',
        'ActivityIndicator',
        'Switch',
        'Pressable',
        'KeyboardAvoidingView',
        'StyleSheet',
    ];

    public static function sourceFor(string $name): ?string
    {
        return in_array($name, self::REACT_NATIVE_COMPONENTS, true) ? 'react-native' : null;
    }

    /** @return string[] */
    public static function knownComponents(): array
    {
        return self::REACT_NATIVE_COMPONENTS;
    }
}