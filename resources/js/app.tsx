import React, { useState, useEffect, Suspense, lazy } from 'react';
import { createRoot } from 'react-dom/client';
import '../css/scss/custom.scss';
import ChunkErrorBoundary from './Components/Common/ChunkErrorBoundary';
import PageLoadingFallback from './Components/Common/PageLoadingFallback';
import { useAuth } from './Hooks/useAuth';
import { resolveDefaultRouteForRole } from './Domain/Auth/roleResolver';
import { NavigationService } from './Domain/Routing/navigationService';

// Tách nhỏ bundle (Code Splitting) với React.lazy để tải trang ban đầu tức thì
const Home = lazy(() => import('./Pages/Home'));
const ManagementHome = lazy(() => import('./Pages/ManagementHome'));
const ResidentHome = lazy(() => import('./Pages/ResidentHome'));
const ReceptionHome = lazy(() => import('./Pages/ReceptionHome'));
const DevConsolePage = lazy(() => import('./Pages/Dev/DevConsolePage'));
const PublicStatusPage = lazy(() => import('./Pages/PublicStatusPage'));
const IncidentHistoryPage = lazy(() => import('./Pages/IncidentHistoryPage'));
const AccountActivationPage = lazy(() => import('./Pages/Auth/AccountActivationPage'));
const LoginPage = lazy(() => import('./Pages/Auth/LoginPage'));
const RegisterPage = lazy(() => import('./Pages/Auth/RegisterPage'));
const ForgotPasswordPage = lazy(() => import('./Pages/Auth/ForgotPasswordPage'));
const NotFound = lazy(() => import('./Pages/NotFound'));

const App: React.FC = () => {
  const [currentPath, setCurrentPath] = useState<string>(() => window.location.pathname);
  const { currentUser, login, logout } = useAuth();

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
    const clean = path.split('?')[0];
    setCurrentPath(clean);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const handleLoginSuccess = (role: string, email: string, residentType?: string) => {
    const session = login(role, email, residentType);
    const destination = resolveDefaultRouteForRole(session);
    setTimeout(() => {
      navigateTo(destination);
    }, 350);
  };

  const handleLogout = () => {
    logout();
    navigateTo('/home');
  };

  const renderContent = () => {
    const match = NavigationService.matchRoute(currentPath, currentUser);

    // Xử lý chuyển hướng nếu route yêu cầu (ví dụ bảo vệ quyền truy cập)
    if (match.redirect) {
      navigateTo(match.redirect);
      return null;
    }

    switch (match.category) {
      case 'public_status':
        return (
          <PublicStatusPage
            onBackHome={() => navigateTo('/home')}
            onNavigateIncidents={() => navigateTo('/status/incidents')}
          />
        );

      case 'status_incidents':
        return (
          <IncidentHistoryPage
            onBackStatus={() => navigateTo('/status')}
            onBackHome={() => navigateTo('/home')}
          />
        );

      case 'account_activation':
        return <AccountActivationPage />;

      case 'dev_login_redirect':
        navigateTo('/login');
        return null;

      case 'admin_dev':
        return (
          <DevConsolePage
            onLogout={() => {
              handleLogout();
              navigateTo('/login');
            }}
            onNavigateHome={() => navigateTo('/home')}
            onNavigateManager={() => navigateTo('/quan-ly')}
            userName={currentUser?.name || 'Admin & Dev Team'}
            userEmail={currentUser?.email || 'admin@cassavas.vn'}
            initialTab={match.initialTab || 'overview'}
          />
        );

      case 'manager': {
        const isUserAdmin = currentUser?.role === 'admin';
        return (
          <ManagementHome
            onLogout={handleLogout}
            onNavigateHome={() => navigateTo('/home')}
            userRole="manager"
            userName={currentUser?.name || (isUserAdmin ? 'Admin Cassavas' : 'Ban Quản Lý')}
            userEmail={currentUser?.email || (isUserAdmin ? 'admin@cassavas.vn' : 'quanly@cassavas.vn')}
            initialTab={match.initialTab}
          />
        );
      }

      case 'resident': {
        const isUserAdmin = currentUser?.role === 'admin';
        const effectiveRole = isUserAdmin ? 'admin' : 'resident';
        return (
          <ResidentHome
            onLogout={handleLogout}
            onNavigateHome={() => navigateTo('/home?landing=true')}
            onNavigateAdmin={() => navigateTo('/admin')}
            userRole={effectiveRole}
            residentType={currentUser?.resident_type}
            userName={currentUser?.name || (isUserAdmin ? 'Admin Cassavas' : 'Nguyễn Văn An')}
            userEmail={currentUser?.email || (isUserAdmin ? 'admin@cassavas.vn' : 'nguyenvanan@cassavas.vn')}
          />
        );
      }

      case 'receptionist': {
        const isUserAdmin = currentUser?.role === 'admin';
        const effectiveRole = isUserAdmin ? 'admin' : (match.isSecurity ? 'security' : 'receptionist');
        return (
          <ReceptionHome
            onLogout={handleLogout}
            onNavigateHome={() => navigateTo('/home?landing=true')}
            onNavigateAdmin={() => navigateTo('/admin')}
            userRole={effectiveRole}
            userName={currentUser?.name || (isUserAdmin ? 'Admin Cassavas' : (match.isSecurity ? 'Đội Trực An Ninh' : 'Lễ Tân Sảnh Chính'))}
            userEmail={currentUser?.email || (isUserAdmin ? 'admin@cassavas.vn' : (match.isSecurity ? 'anninh@cassavas.vn' : 'letan@cassavas.vn'))}
            initialTab={match.initialTab}
          />
        );
      }

      case 'login':
        return (
          <LoginPage
            onNavigate={navigateTo}
            onLoginSuccess={handleLoginSuccess}
          />
        );

      case 'register':
        return (
          <RegisterPage
            onNavigate={navigateTo}
            onRegisterSuccess={handleLoginSuccess}
          />
        );

      case 'forgot_password':
        return (
          <ForgotPasswordPage
            onNavigate={navigateTo}
          />
        );

      case 'home':
        return (
          <Home
            onLoginSuccess={handleLoginSuccess}
            onNavigateLogin={(role) => navigateTo(role ? `/login?role=${role}` : '/login')}
            onNavigateRegister={(type) => navigateTo(type ? `/register?type=${type}` : '/register')}
            onNavigateAdmin={() => navigateTo('/admin')}
            onNavigateManager={() => navigateTo('/quan-ly')}
            onNavigateResident={() => navigateTo('/cu-dan')}
            onNavigateReception={() => navigateTo('/le-tan')}
            currentUserRole={currentUser?.role}
          />
        );

      case 'not_found':
      default:
        return <NotFound onBackHome={() => navigateTo('/home')} />;
    }
  };

  return (
    <ChunkErrorBoundary>
      <Suspense fallback={<PageLoadingFallback />}>
        {renderContent()}
      </Suspense>
    </ChunkErrorBoundary>
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
export default App;
