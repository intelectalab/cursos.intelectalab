import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './app/App.tsx'
import { listenForInstall } from './shared/pwa/installableApp'

// El navegador ofrece instalar una sola vez, al cargar: se escucha antes de montar la aplicación
listenForInstall()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
