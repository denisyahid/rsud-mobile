import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.jsx'
import '@fortawesome/fontawesome-free/css/all.min.css';
import { initRipple } from './lib/ripple';
import { initCapacitorBack } from './lib/capacitorBack';

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <App />
  </StrictMode>,
)

// Ripple Material Design untuk semua tombol (efek sentuh khas Android)
initRipple(document.body);

// Tombol Back Android mengikuti navigasi React (no-op di web/browser)
initCapacitorBack();
