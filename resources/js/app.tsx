import React, { useState, useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import Home from './Pages/Home';
import NotFound from './Pages/NotFound';

const App: React.FC = () => {
  const [currentPath, setCurrentPath] = useState<string>(() => window.location.pathname);

  useEffect(() => {
    const handlePopState = () => {
      setCurrentPath(window.location.pathname);
    };
    window.addEventListener('popstate', handlePopState);
    return () => window.removeEventListener('popstate', handlePopState);
  }, []);

  const navigateToHome = () => {
    window.history.pushState({}, '', '/');
    setCurrentPath('/');
  };

  // Valid paths for single-page application
  const isHomePage = currentPath === '/' || currentPath === '';

  if (!isHomePage) {
    return <NotFound onBackHome={navigateToHome} />;
  }

  return <Home />;
};

const rootElement = document.getElementById('app');

if (rootElement) {
  const root = createRoot(rootElement);
  root.render(
    <React.StrictMode>
      <App />
    </React.StrictMode>
  );
}

