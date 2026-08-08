/**
 * Ponte entre o projeto RN e o output do Frost. NUNCA contém lógica própria
 * — só reexporta o que o CLI (`frost build`/`frost dev`) escreveu em
 * src/generated/App.js. Se o import falhar, rode `frost build` primeiro.
 */
import App from './src/generated/App';

export default App;