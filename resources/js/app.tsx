import React, { useState, useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import Home from './Pages/Home';
import ManagementHome from './Pages/ManagementHome';
import NotFound from './Pages/NotFound';
import AmenityManagement from './Pages/Admin/AmenityManagement';

interface UserSession {
  role: string;
  email: string;
  name: string;
}

const App: React.FC = () => {
  const [currentPath, setCurrentPath] = useState<string>(() => window.location.pathname);
  const [currentUser, setCurrentUser] = useState<UserSession | null>(() => {
    try {
      const saved = localStorage.getItem('smartcassavas_session');
      return saved ? JSON.parse(saved) : null;
    } catch {
      return null;
    }
  });

  useEffect(() => {
    const handlePopState = () => {
      setCurrentPath(window.location.pathname);
    };
    window.addEventListener('popstate', handlePopState);
    return () => window.removeEventListener('popstate', handlePopState);
  }, []);

  const navigateTo = (path: string) => {
    window.history.pushState({}, '', path);
    setCurrentPath(path);
  };

  const handleLoginSuccess = (role: string, email: string) => {
    const session: UserSession = {
      role,
      email: email || (role === 'admin' ? 'admin@cassavas.vn' : 'quanly@cassavas.vn'),
      name: role === 'admin' ? 'Admin Cassavas' : role === 'manager' ? 'Ban Quản Lý' : 'Cư Dân Cassavas',
    };
    try {
      localStorage.setItem('smartcassavas_session', JSON.stringify(session));
    } catch {
      // ignore
    }
    setCurrentUser(session);

    // Khi đăng nhập vai trò quản lý / admin, tự động chuyển vào trang quản lý
    if (role === 'manager' || role === 'admin') {
      setTimeout(() => {
        navigateTo('/admin');
      }, 400);
    }
  };

  const handleLogout = () => {
    try {
      localStorage.removeItem('smartcassavas_session');
    } catch {
      // ignore
    }
    setCurrentUser(null);
    navigateTo('/');
  };

  // Các đường dẫn trang quản lý
  const isAmenityAdminPath =
    currentPath === '/admin/amenities' ||
    currentPath === '/admin/tien-ich' ||
    currentPath.startsWith('/admin/amenities');

  const isAdminPath =
    currentPath === '/admin' ||
    currentPath === '/dashboard' ||
    currentPath === '/quan-ly' ||
    currentPath === '/manager' ||
    currentPath.startsWith('/admin/') ||
    isAmenityAdminPath;

  if (isAdminPath) {
    const urlTab = new URLSearchParams(window.location.search).get('tab');
    const tabToUse = isAmenityAdminPath ? 'amenities' : (urlTab || undefined);

    return (
      <ManagementHome
        onLogout={handleLogout}
        onNavigateHome={() => navigateTo('/')}
        userRole={currentUser?.role || 'admin'}
        userName={currentUser?.name || 'Admin Cassavas'}
        userEmail={currentUser?.email || 'admin@cassavas.vn'}
        initialTab={tabToUse}
      />
    );
  }

  // Valid paths for single-page application
  const isAuthPath =
    currentPath === '/login' ||
    currentPath === '/register' ||
    currentPath === '/dang-nhap' ||
    currentPath === '/dang-ky';
  const isHomePage = currentPath === '/' || currentPath === '' || isAuthPath;

  if (!isHomePage) {
    return <NotFound onBackHome={() => navigateTo('/')} />;
  }

  const initialAuthMode =
    currentPath === '/register' || currentPath === '/dang-ky'
      ? 'register'
      : currentPath === '/login' || currentPath === '/dang-nhap'
      ? 'login'
      : null;

  return (
    <Home
      initialAuthModal={initialAuthMode}
      onLoginSuccess={handleLoginSuccess}
      onNavigateAdmin={() => navigateTo('/admin')}
      currentUserRole={currentUser?.role}
    />
  );
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
