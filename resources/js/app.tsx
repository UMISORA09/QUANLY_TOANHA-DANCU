import React from 'react';
import { createRoot } from 'react-dom/client';
import Home from './Pages/Home';

const rootElement = document.getElementById('app');

if (rootElement) {
  const root = createRoot(rootElement);
  root.render(
    <React.StrictMode>
      <Home />
    </React.StrictMode>
  );
}
