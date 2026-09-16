import React, { useState, useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import Home from './Pages/Home';
import ManagementHome from './Pages/ManagementHome';
import ResidentHome from './Pages/ResidentHome';
import ReceptionHome from './Pages/ReceptionHome';
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
    if (window.location.pathname === '/') {
      window.history.replaceState({}, '', '/home');
      setCurrentPath('/home');
    }
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
    const isResident = role === 'resident' || role.toLowerCase().includes('resident');
    const isReceptionist =
      role === 'receptionist' ||
      role.toLowerCase().includes('receptionist') ||
      role.toLowerCase().includes('letan') ||
      role.toLowerCase().includes('lễ tân');

    const session: UserSession = {
      role: isReceptionist ? 'receptionist' : isResident ? 'resident' : role,
      email:
        email ||
        (role === 'admin'
          ? 'admin@cassavas.vn'
          : isReceptionist
          ? 'admin@cassavas.vn'
          : isResident
          ? 'nguyenvanan@cassavas.vn'
          : 'quanly@cassavas.vn'),
      name:
        role === 'admin'
          ? 'Admin Cassavas'
          : isReceptionist
          ? 'Admin Cassavas'
          : isResident
          ? 'Nguyễn Văn An'
          : role === 'manager'
          ? 'Ban Quản Lý'
          : 'Cư Dân Cassavas',
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
    } else if (isResident) {
      setTimeout(() => {
        navigateTo('/cu-dan');
      }, 400);
    } else if (isReceptionist) {
      setTimeout(() => {
        navigateTo('/le-tan');
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
    navigateTo('/home');
  };

  // Các đường dẫn trang quản lý
  const isAdminPath =
    currentPath === '/admin' ||
    currentPath === '/dashboard' ||
    currentPath === '/quan-ly' ||
    currentPath === '/manager';

  if (isAdminPath) {
    return (
      <ManagementHome
        onLogout={handleLogout}
        onNavigateHome={() => navigateTo('/home')}
        userRole={currentUser?.role || 'admin'}
        userName={currentUser?.name || 'Admin Cassavas'}
        userEmail={currentUser?.email || 'admin@cassavas.vn'}
      />
    );
  }

  // Các đường dẫn Cổng Cư Dân
  const isExplicitLanding = window.location.search.includes('landing=true');
  const isResidentSession =
    currentUser?.role === 'resident' || currentUser?.role?.toLowerCase().includes('resident');

  const isResidentPath =
    currentPath === '/cu-dan' ||
    currentPath.startsWith('/cu-dan') ||
    currentPath === '/resident' ||
    currentPath.startsWith('/resident') ||
    currentPath === '/resident-portal' ||
    (isResidentSession && (currentPath === '/' || currentPath === '' || currentPath === '/home') && !isExplicitLanding);

  if (isResidentPath) {
    return (
      <ResidentHome
        onLogout={handleLogout}
        onNavigateHome={() => navigateTo('/home?landing=true')}
        onNavigateAdmin={() => navigateTo('/admin')}
        userRole={currentUser?.role || 'resident'}
        userName={currentUser?.name || 'Nguyễn Văn An'}
        userEmail={currentUser?.email || 'nguyenvanan@cassavas.vn'}
      />
    );
  }

  // Các đường dẫn Cổng Lễ Tân & Bảo Vệ
  const isReceptionistSession =
    currentUser?.role === 'receptionist' ||
    currentUser?.role?.toLowerCase().includes('receptionist') ||
    currentUser?.role?.toLowerCase().includes('letan');

  const isReceptionistPath =
    currentPath === '/le-tan' ||
    currentPath.startsWith('/le-tan') ||
    currentPath === '/receptionist' ||
    currentPath.startsWith('/receptionist') ||
    (isReceptionistSession && (currentPath === '/' || currentPath === '' || currentPath === '/home') && !isExplicitLanding);

  if (isReceptionistPath) {
    return (
      <ReceptionHome
        onLogout={handleLogout}
        onNavigateHome={() => navigateTo('/home?landing=true')}
        onNavigateAdmin={() => navigateTo('/admin')}
        userRole={currentUser?.role || 'receptionist'}
        userName={currentUser?.name || 'Admin Cassavas'}
        userEmail={currentUser?.email || 'admin@cassavas.vn'}
      />
    );
  }

  // Valid paths for single-page application
  const isAuthPath =
    currentPath === '/login' ||
    currentPath === '/register' ||
    currentPath === '/dang-nhap' ||
    currentPath === '/dang-ky';
  const isAmenityAdminPath =
    currentPath === '/admin/amenities' ||
    currentPath === '/admin/tien-ich';
  const isHomePage =
    currentPath === '/' ||
    currentPath === '' ||
    currentPath === '/home' ||
    currentPath.startsWith('/home') ||
    isAuthPath;

  if (isAmenityAdminPath) {
    return (
      <ManagementHome
        onLogout={handleLogout}
        onNavigateHome={() => navigateTo('/home')}
        userRole={currentUser?.role || 'admin'}
        userName={currentUser?.name || 'Admin Cassavas'}
        userEmail={currentUser?.email || 'admin@cassavas.vn'}
        initialTab="amenities"
      />
    );
  }

  if (!isHomePage) {
    return <NotFound onBackHome={() => navigateTo('/home')} />;
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
      onNavigateResident={() => navigateTo('/cu-dan')}
      onNavigateReception={() => navigateTo('/le-tan')}
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


