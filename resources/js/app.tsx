import React, { useState, useEffect, Suspense, lazy } from 'react';
import { createRoot } from 'react-dom/client';
import '../css/scss/custom.scss';
import ChunkErrorBoundary from './Components/Common/ChunkErrorBoundary';
import PageLoadingFallback from './Components/Common/PageLoadingFallback';

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

interface UserSession {
  role: string;
  resident_type?: 'OWNER' | 'TENANT';
  email: string;
  name: string;
  isDev?: boolean;
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
    const clean = path.split('?')[0];
    setCurrentPath(clean);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const handleLoginSuccess = (role: string, email: string, residentType?: string) => {
    const isDev =
      email.toLowerCase().includes('dev') ||
      email === 'dev@cassavas.vn' ||
      email === 'dev@smartcassavas.vn' ||
      window.location.pathname.startsWith('/dev');
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

    const normalizedRole = isAdmin || isDev
      ? 'admin'
      : isManager
      ? 'manager'
      : isReceptionist
      ? 'receptionist'
      : 'resident';

    const session: UserSession = {
      role: normalizedRole,
      resident_type: (residentType === 'TENANT' ? 'TENANT' : 'OWNER') as any,
      isDev: isDev,
      email:
        email ||
        (isDev
          ? 'dev@cassavas.vn'
          : isAdmin
          ? 'admin@cassavas.vn'
          : isManager
          ? 'quanly@cassavas.vn'
          : isReceptionist
          ? 'letan@cassavas.vn'
          : 'nguyenvanan@cassavas.vn'),
      name:
        isDev
          ? 'Dev Team'
          : isAdmin
          ? 'Admin Cassavas'
          : isManager
          ? 'Ban Quản Lý'
          : isReceptionist
          ? 'Lễ Tân Sảnh Chính'
          : (residentType === 'TENANT' ? 'Khách Thuê Căn Hộ' : 'Nguyễn Văn An'),
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
    // Admin / Dev điều hướng trực tiếp vào Cổng Quản Trị & Kỹ Thuật (/admin)
    if (isDev || isAdmin) {
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
        if (residentType === 'TENANT') {
          navigateTo('/cu-dan?tab=rentals');
        } else {
          navigateTo('/cu-dan');
        }
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

  const renderContent = () => {

  // 0. Phân hệ Public Status Page (Công khai cho toàn bộ người dùng theo dõi hệ thống)
  if (currentPath === '/status' || currentPath === '/status/') {
    return (
      <PublicStatusPage
        onBackHome={() => navigateTo('/home')}
        onNavigateIncidents={() => navigateTo('/status/incidents')}
      />
    );
  }

  if (currentPath === '/status/incidents' || currentPath.startsWith('/status/incidents')) {
    return (
      <IncidentHistoryPage
        onBackStatus={() => navigateTo('/status')}
        onBackHome={() => navigateTo('/home')}
      />
    );
  }

  // 0.2 Phân hệ Kích hoạt tài khoản cư dân (Public Activation Token Page)
  if (
    currentPath === '/kich-hoat-tai-khoan' ||
    currentPath.startsWith('/kich-hoat-tai-khoan') ||
    currentPath === '/activate-account' ||
    currentPath.startsWith('/activate-account')
  ) {
    return <AccountActivationPage />;
  }

  // 0.1 Nếu truy cập các URL đăng nhập dev cũ, tự động chuyển về trang /login chung
  const isDevLoginPath =
    currentPath === '/dev/login' ||
    currentPath === '/admin/login' ||
    currentPath === '/dev/dang-nhap' ||
    currentPath === '/admin/dang-nhap';

  if (isDevLoginPath) {
    navigateTo('/login');
    return null;
  }

  // 1. Tuyến đường Quản Lý Tiện Ích: Đã chuyển toàn bộ sang Cổng Ban Quản Lý (/quan-ly?tab=amenities)
  const isAmenityPath =
    currentPath === '/admin/amenities' ||
    currentPath === '/admin/tien-ich' ||
    currentPath.startsWith('/admin/amenities') ||
    currentPath === '/quan-ly/amenities' ||
    currentPath === '/quan-ly/tien-ich' ||
    currentPath.startsWith('/quan-ly/amenities') ||
    currentPath === '/tien-ich';

  // 1.1 Phân hệ Quản Trị Viên & Kỹ Thuật (Admin & Dev Console HỢP NHẤT LÀ 1)
  const isCicdAdminPath =
    currentPath === '/admin/cicd' ||
    currentPath.startsWith('/admin/cicd') ||
    currentPath === '/devops' ||
    currentPath.startsWith('/devops') ||
    currentPath === '/admin/devops' ||
    currentPath.startsWith('/admin/devops');

  const isRoleAdminPath =
    currentPath === '/admin/roles' ||
    currentPath === '/admin/rbac' ||
    currentPath === '/admin/phan-quyen' ||
    currentPath.startsWith('/admin/roles') ||
    currentPath.startsWith('/admin/rbac');

  const isResidentAdminPath =
    currentPath === '/admin/residents' ||
    currentPath === '/admin/cu-dan' ||
    currentPath.startsWith('/admin/residents');

  const isTemporaryRegistrationAdminPath =
    currentPath === '/admin/temporary-registrations' ||
    currentPath === '/admin/tam-tru' ||
    currentPath.startsWith('/admin/temporary-registrations');

  const isUnifiedAdminDevPath =
    !isAmenityPath &&
    (currentPath === '/admin' ||
      currentPath.startsWith('/admin/') ||
      currentPath === '/dev' ||
      currentPath.startsWith('/dev/') ||
      currentPath === '/developer' ||
      currentPath.startsWith('/developer/') ||
      isCicdAdminPath ||
      isRoleAdminPath ||
      isResidentAdminPath ||
      isTemporaryRegistrationAdminPath);

  if (isUnifiedAdminDevPath) {
    // Bảo vệ quyền: Nếu người dùng đã đăng nhập vai trò khác không phải Admin/Dev, chuyển về đúng cổng của họ
    if (currentUser && currentUser.role !== 'admin' && !currentUser.isDev) {
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
    const tabToUse = isCicdAdminPath
      ? 'cicd'
      : isRoleAdminPath
      ? 'roles'
      : isResidentAdminPath
      ? 'residents'
      : isTemporaryRegistrationAdminPath
      ? 'temporary_registrations'
      : (urlTab || 'overview');

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
        initialTab={tabToUse}
      />
    );
  }

  // 2. Phân hệ Ban Quản Lý (Building Management - Vận hành tòa nhà & Quản lý tiện ích)
  const isAccountProvisioningPath =
    currentPath === '/quan-ly/account-provisioning' ||
    currentPath === '/quan-ly/cap-phat-tai-khoan' ||
    currentPath.startsWith('/quan-ly/account-provisioning') ||
    currentPath.startsWith('/quan-ly/cap-phat-tai-khoan') ||
    currentPath === '/admin/account-provisioning' ||
    currentPath === '/admin/cap-phat-tai-khoan' ||
    currentPath.startsWith('/admin/account-provisioning');

  const isVehiclePath =
    currentPath === '/quan-ly/vehicles' ||
    currentPath === '/quan-ly/phuong-tien' ||
    currentPath.startsWith('/quan-ly/vehicles') ||
    currentPath === '/admin/vehicles' ||
    currentPath === '/admin/phuong-tien' ||
    currentPath.startsWith('/admin/vehicles');

  const isPricingPath =
    currentPath === '/quan-ly/don-gia' ||
    currentPath === '/quan-ly/pricing-configs' ||
    currentPath === '/admin/pricing-configs' ||
    currentPath.startsWith('/quan-ly/pricing-configs') ||
    currentPath.startsWith('/quan-ly/don-gia');

  const isMeterReadingPath =
    currentPath === '/quan-ly/chot-chi-so' ||
    currentPath === '/quan-ly/meter-readings' ||
    currentPath === '/admin/meter-readings' ||
    currentPath.startsWith('/quan-ly/chot-chi-so') ||
    currentPath.startsWith('/admin/meter-readings');

  const isInvoicePath =
    currentPath === '/quan-ly/hoa-don' ||
    currentPath === '/quan-ly/invoices' ||
    currentPath === '/admin/hoa-don' ||
    currentPath === '/admin/invoices' ||
    currentPath.startsWith('/quan-ly/hoa-don') ||
    currentPath.startsWith('/quan-ly/invoices');

  const isInvoiceGenerationPath =
    currentPath === '/quan-ly/sinh-hoa-don' ||
    currentPath === '/quan-ly/invoice-generation' ||
    currentPath === '/admin/sinh-hoa-don' ||
    currentPath === '/admin/invoice-generation' ||
    currentPath.startsWith('/quan-ly/sinh-hoa-don') ||
    currentPath.startsWith('/quan-ly/invoice-generation');

  const isPaymentHistoryPath =
    currentPath === '/quan-ly/lich-su-giao-dich' ||
    currentPath === '/quan-ly/payment-history' ||
    currentPath === '/admin/lich-su-giao-dich' ||
    currentPath === '/admin/payment-history' ||
    currentPath.startsWith('/quan-ly/lich-su-giao-dich') ||
    currentPath.startsWith('/quan-ly/payment-history');

  const isRevenueAnalyticsPath =
    currentPath === '/quan-ly/thong-ke-doanh-thu' ||
    currentPath === '/quan-ly/revenue-analytics' ||
    currentPath === '/admin/thong-ke-doanh-thu' ||
    currentPath === '/admin/revenue-analytics' ||
    currentPath.startsWith('/quan-ly/thong-ke-doanh-thu') ||
    currentPath.startsWith('/quan-ly/revenue-analytics');

  const isFinancialReportPath =
    currentPath === '/quan-ly/bao-cao-tai-chinh' ||
    currentPath === '/quan-ly/financial-reports' ||
    currentPath === '/admin/bao-cao-tai-chinh' ||
    currentPath === '/admin/financial-reports' ||
    currentPath.startsWith('/quan-ly/bao-cao-tai-chinh') ||
    currentPath.startsWith('/quan-ly/financial-reports');

  const isRfidPath =
    currentPath === '/quan-ly/rfid-cards' ||
    currentPath === '/quan-ly/rfid' ||
    currentPath.startsWith('/quan-ly/rfid') ||
    currentPath === '/admin/rfid-cards' ||
    currentPath === '/admin/rfid' ||
    currentPath.startsWith('/admin/rfid');

  const isManagerPath =
    currentPath === '/quan-ly' ||
    currentPath.startsWith('/quan-ly/') ||
    currentPath === '/manager' ||
    currentPath.startsWith('/manager/') ||
    currentPath === '/dashboard' ||
    isAmenityPath ||
    isAccountProvisioningPath ||
    isVehiclePath ||
    isPricingPath ||
    isMeterReadingPath ||
    isInvoicePath ||
    isInvoiceGenerationPath ||
    isPaymentHistoryPath ||
    isRevenueAnalyticsPath ||
    isFinancialReportPath ||
    isRfidPath;

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
    const managerUrlTab = new URLSearchParams(window.location.search).get('tab');
    const initialManagerTab = isAmenityPath
      ? 'amenities'
      : isVehiclePath
      ? 'vehicles'
      : isPricingPath
      ? 'pricing_configs'
      : isMeterReadingPath
      ? 'meter_readings'
      : isRfidPath
      ? 'rfid_cards'
      : isAccountProvisioningPath
      ? 'account_provisioning'
      : isInvoiceGenerationPath
      ? 'invoice_generation'
      : isPaymentHistoryPath
      ? 'payment_history'
      : isRevenueAnalyticsPath
      ? 'revenue_analytics'
      : isFinancialReportPath
      ? 'financial_reports'
      : isInvoicePath
      ? 'invoices'
      : (managerUrlTab || undefined);

    return (
      <ManagementHome
        onLogout={handleLogout}
        onNavigateHome={() => navigateTo('/home')}
        userRole="manager"
        userName={currentUser?.name || (isUserAdmin ? 'Admin Cassavas' : 'Ban Quản Lý')}
        userEmail={currentUser?.email || (isUserAdmin ? 'admin@cassavas.vn' : 'quanly@cassavas.vn')}
        initialTab={initialManagerTab}
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
        residentType={currentUser?.resident_type}
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

  const isSecurityPath =
    currentPath === '/an-ninh' ||
    currentPath.startsWith('/an-ninh') ||
    currentPath === '/bao-ve' ||
    currentPath.startsWith('/bao-ve') ||
    currentPath === '/security' ||
    currentPath.startsWith('/security');

  const isReceptionistVehiclePath =
    currentPath === '/le-tan/vehicles' ||
    currentPath === '/le-tan/phuong-tien' ||
    currentPath.startsWith('/le-tan/vehicles') ||
    currentPath.startsWith('/le-tan/phuong-tien') ||
    currentPath === '/an-ninh/vehicles' ||
    currentPath === '/an-ninh/phuong-tien' ||
    currentPath.startsWith('/an-ninh/vehicles');

  const isReceptionistPath =
    currentPath === '/le-tan' ||
    currentPath.startsWith('/le-tan') ||
    currentPath === '/receptionist' ||
    currentPath.startsWith('/receptionist') ||
    isSecurityPath ||
    isReceptionistVehiclePath ||
    (isReceptionistSession && (currentPath === '/' || currentPath === '' || currentPath === '/home') && !isExplicitLanding);

  if (isReceptionistPath) {
    const isUserAdmin = currentUser?.role === 'admin';
    const effectiveRole = isUserAdmin ? 'admin' : (isSecurityPath ? 'security' : 'receptionist');
    const receptionUrlTab = new URLSearchParams(window.location.search).get('tab');
    const initialReceptionTab = isReceptionistVehiclePath
      ? 'vehicle_reg'
      : (receptionUrlTab || undefined);
    return (
      <ReceptionHome
        onLogout={handleLogout}
        onNavigateHome={() => navigateTo('/home?landing=true')}
        onNavigateAdmin={() => navigateTo('/admin')}
        userRole={effectiveRole}
        userName={currentUser?.name || (isUserAdmin ? 'Admin Cassavas' : (isSecurityPath ? 'Đội Trực An Ninh' : 'Lễ Tân Sảnh Chính'))}
        userEmail={currentUser?.email || (isUserAdmin ? 'admin@cassavas.vn' : (isSecurityPath ? 'anninh@cassavas.vn' : 'letan@cassavas.vn'))}
        initialTab={initialReceptionTab}
      />
    );
  }

  // 5. Phân hệ Xác thực độc lập (Dedicated Auth Pages: Đăng nhập, Đăng ký, Quên mật khẩu & OTP)
  const isLoginPage = currentPath === '/login' || currentPath === '/dang-nhap';
  const isRegisterPage = currentPath === '/register' || currentPath === '/dang-ky';
  const isForgotPasswordPage =
    currentPath === '/forgot-password' ||
    currentPath === '/quen-mat-khau' ||
    currentPath === '/verify-otp' ||
    currentPath === '/reset-password';

  if (isLoginPage) {
    return (
      <LoginPage
        onNavigate={navigateTo}
        onLoginSuccess={handleLoginSuccess}
      />
    );
  }

  if (isRegisterPage) {
    return (
      <RegisterPage
        onNavigate={navigateTo}
        onRegisterSuccess={handleLoginSuccess}
      />
    );
  }

  if (isForgotPasswordPage) {
    return (
      <ForgotPasswordPage
        onNavigate={navigateTo}
      />
    );
  }

  // 6. Trang chủ công khai (Home)
  const isHomePage =
    currentPath === '/' ||
    currentPath === '' ||
    currentPath === '/home' ||
    currentPath.startsWith('/home');

  if (!isHomePage) {
    return <NotFound onBackHome={() => navigateTo('/home')} />;
  }

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
