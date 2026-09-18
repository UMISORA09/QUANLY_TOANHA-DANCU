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
    const isAdmin =
      role === 'admin' ||
      role.toLowerCase() === 'admin' ||
      role.toLowerCase() === 'super_admin';
    const isManager =
      role === 'manager' ||
      role.toLowerCase() === 'manager' ||
      role.toLowerCase() === 'building_manager';
    const isReceptionist =
      role === 'receptionist' ||
      role.toLowerCase().includes('receptionist') ||
      role.toLowerCase().includes('letan') ||
      role.toLowerCase().includes('lễ tân');
    const isResident =
      role === 'resident' ||
      role.toLowerCase().includes('resident');

    const normalizedRole = isAdmin
      ? 'admin'
      : isManager
      ? 'manager'
      : isReceptionist
      ? 'receptionist'
      : 'resident';

    const session: UserSession = {
      role: normalizedRole,
      email:
        email ||
        (isAdmin
          ? 'admin@cassavas.vn'
          : isManager
          ? 'quanly@cassavas.vn'
          : isReceptionist
          ? 'letan@cassavas.vn'
          : 'nguyenvanan@cassavas.vn'),
      name:
        isAdmin
          ? 'Admin Cassavas'
          : isManager
          ? 'Ban Quản Lý'
          : isReceptionist
          ? 'Lễ Tân Sảnh Chính'
          : 'Nguyễn Văn An',
    };
    try {
      localStorage.setItem('smartcassavas_session', JSON.stringify(session));
      sessionStorage.removeItem('smartcassavas_active_admin_tab');
      localStorage.removeItem('smartcassavas_active_admin_tab');
    } catch {
      // ignore
    }
    setCurrentUser(session);

    // Điều hướng theo đúng vai trò được xác thực
    // Chỉ có admin mới vào /admin và có quyền chuyển cổng
    if (isAdmin) {
      setTimeout(() => {
        navigateTo('/admin');
      }, 350);
    } else if (isManager) {
      setTimeout(() => {
        navigateTo('/quan-ly');
      }, 350);
    } else if (isReceptionist) {
      setTimeout(() => {
        navigateTo('/le-tan');
      }, 350);
    } else {
      setTimeout(() => {
        navigateTo('/cu-dan');
      }, 350);
    }
  };

  const handleLogout = () => {
    try {
      localStorage.removeItem('smartcassavas_session');
      sessionStorage.removeItem('smartcassavas_active_admin_tab');
      localStorage.removeItem('smartcassavas_active_admin_tab');
    } catch {
      // ignore
    }
    setCurrentUser(null);
    navigateTo('/home');
  };

  // 1. Phân hệ Quản Trị Viên (Admin Console - Toàn quyền & Chuyển cổng)
  const isAmenityAdminPath =
    currentPath === '/admin/amenities' ||
    currentPath === '/admin/tien-ich' ||
    currentPath.startsWith('/admin/amenities');

  const isRoleAdminPath =
    currentPath === '/admin/roles' ||
    currentPath === '/admin/rbac' ||
    currentPath === '/admin/phan-quyen' ||
    currentPath.startsWith('/admin/roles') ||
    currentPath.startsWith('/admin/rbac');

  const isAdminPath =
    currentPath === '/admin' ||
    currentPath.startsWith('/admin/') ||
    isAmenityAdminPath ||
    isRoleAdminPath;

  if (isAdminPath) {
    // Bảo vệ quyền: Nếu người dùng đã đăng nhập vai trò khác không phải Admin, chuyển về đúng cổng của họ
    if (currentUser && currentUser.role !== 'admin') {
      if (currentUser.role === 'manager') {
        navigateTo('/quan-ly');
        return null;
      }
      if (currentUser.role === 'receptionist') {
        navigateTo('/le-tan');
        return null;
      }
      if (currentUser.role === 'resident') {
        navigateTo('/cu-dan');
        return null;
      }
    }

    const urlTab = new URLSearchParams(window.location.search).get('tab');
    const tabToUse = isAmenityAdminPath ? 'amenities' : isRoleAdminPath ? 'roles' : (urlTab || undefined);

    return (
      <ManagementHome
        onLogout={handleLogout}
        onNavigateHome={() => navigateTo('/home')}
        userRole="admin"
        userName={currentUser?.role === 'admin' ? currentUser.name : 'Admin Cassavas'}
        userEmail={currentUser?.role === 'admin' ? currentUser.email : 'admin@cassavas.vn'}
        initialTab={tabToUse}
      />
    );
  }

  // 2. Phân hệ Ban Quản Lý (Building Management - Vận hành tòa nhà)
  const isManagerPath =
    currentPath === '/quan-ly' ||
    currentPath.startsWith('/quan-ly/') ||
    currentPath === '/manager' ||
    currentPath.startsWith('/manager/') ||
    currentPath === '/dashboard';

  if (isManagerPath) {
    // Bảo vệ quyền: Nếu là lễ tân hoặc cư dân cố vào trang quản lý, chuyển về cổng tương ứng
    if (currentUser && currentUser.role !== 'manager' && currentUser.role !== 'admin') {
      if (currentUser.role === 'receptionist') {
        navigateTo('/le-tan');
        return null;
      }
      if (currentUser.role === 'resident') {
        navigateTo('/cu-dan');
        return null;
      }
    }

    const isUserAdmin = currentUser?.role === 'admin';
    const effectiveRole = isUserAdmin ? 'admin' : 'manager';
    return (
      <ManagementHome
        onLogout={handleLogout}
        onNavigateHome={() => navigateTo('/home')}
        userRole={effectiveRole}
        userName={currentUser?.name || (isUserAdmin ? 'Admin Cassavas' : 'Ban Quản Lý')}
        userEmail={currentUser?.email || (isUserAdmin ? 'admin@cassavas.vn' : 'quanly@cassavas.vn')}
      />
    );
  }

  // 3. Phân hệ Cổng Cư Dân (Resident Portal)
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
    const isUserAdmin = currentUser?.role === 'admin';
    const effectiveRole = isUserAdmin ? 'admin' : 'resident';
    return (
      <ResidentHome
        onLogout={handleLogout}
        onNavigateHome={() => navigateTo('/home?landing=true')}
        onNavigateAdmin={() => navigateTo('/admin')}
        userRole={effectiveRole}
        userName={currentUser?.name || (isUserAdmin ? 'Admin Cassavas' : 'Nguyễn Văn An')}
        userEmail={currentUser?.email || (isUserAdmin ? 'admin@cassavas.vn' : 'nguyenvanan@cassavas.vn')}
      />
    );
  }

  // 4. Phân hệ Cổng Lễ Tân & Bảo Vệ (Reception Portal)
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
    const isUserAdmin = currentUser?.role === 'admin';
    const effectiveRole = isUserAdmin ? 'admin' : 'receptionist';
    return (
      <ReceptionHome
        onLogout={handleLogout}
        onNavigateHome={() => navigateTo('/home?landing=true')}
        onNavigateAdmin={() => navigateTo('/admin')}
        userRole={effectiveRole}
        userName={currentUser?.name || (isUserAdmin ? 'Admin Cassavas' : 'Lễ Tân Sảnh Chính')}
        userEmail={currentUser?.email || (isUserAdmin ? 'admin@cassavas.vn' : 'letan@cassavas.vn')}
      />
    );
  }

  // Valid paths for single-page application
  const isAuthPath =
    currentPath === '/login' ||
    currentPath === '/register' ||
    currentPath === '/dang-nhap' ||
    currentPath === '/dang-ky';
  const isHomePage =
    currentPath === '/' ||
    currentPath === '' ||
    currentPath === '/home' ||
    currentPath.startsWith('/home') ||
    isAuthPath;

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
      onNavigateManager={() => navigateTo('/quan-ly')}
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
