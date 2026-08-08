# Runtime/Bridge — stubs/rn-bridge-template

## O que é
Projeto React Native mínimo que roda de verdade o JS/JSX gerado pelo
Transpiler. É o "chão" onde o output do Frost pousa e vira app via
Metro + Hermes/JSC.

## Pipeline
código PHP → Lexer → Parser → Transpiler → ImportInjector [pacotes PHP]
→ CLI escreve em src/generated/*.js [próximo pacote]
→ App.js reexporta → Metro empacota, Babel compila JSX → Hermes/JSC roda

## Fronteira do escopo (débito consciente)
NÃO gero android/ e ios/ (Gradle/Xcode) na mão: é boilerplate gerado que
muda a cada versão do RN, e não tenho Xcode/Android SDK aqui pra validar.
Fluxo real: `npx @react-native-community/cli init` uma vez pra gerar o
nativo, depois copia estes arquivos (package.json, babel.config.js,
metro.config.js, index.js, App.js, src/generated/) por cima.

## Requisito descoberto pro CLI (próximo pacote)
O Transpiler emite `function App() {...}` mas não `export default App;`
— ele não sabe qual função é a raiz do arquivo. Fica pro CLI: nome do
arquivo .php define o nome da função esperada como export default.