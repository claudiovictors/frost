const { getDefaultConfig, mergeConfig } = require('@react-native/metro-config');

/**
 * Não precisa de nada especial pro Frost hoje: o CLI escreve JS/JSX puro em
 * src/generated/, e o Metro já processa isso com o preset padrão do RN
 * (que já suporta JSX). Fica como ponto de extensão futuro (ex: resolver
 * customizado, watchFolders pro repo do framework via `npm link`).
 */
const config = {};

module.exports = mergeConfig(getDefaultConfig(__dirname), config);