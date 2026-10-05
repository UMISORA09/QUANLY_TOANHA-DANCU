import { UserSession } from '../Auth/roleResolver';

export type RouteCategory =
  | 'public_status'
  | 'status_incidents'
  | 'account_activation'
  | 'dev_login_redirect'
  | 'admin_dev'
  | 'manager'
  | 'resident'
  | 'receptionist'
  | 'login'
  | 'register'
  | 'forgot_password'
  | 'home'
  | 'not_found';

export interface RouteMatch {
  category: RouteCategory;
  initialTab?: string;
  redirect?: string;
  isSecurity?: boolean;
}

export const NavigationService = {
  isAmenityPath(path: string): boolean {
    return (
      path === '/admin/amenities' ||
      path === '/admin/tien-ich' ||
      path.startsWith('/admin/amenities') ||
      path === '/quan-ly/amenities' ||
      path === '/quan-ly/tien-ich' ||
      path.startsWith('/quan-ly/amenities') ||
      path === '/tien-ich'
    );
  },

  isCicdAdminPath(path: string): boolean {
    return (
      path === '/admin/cicd' ||
      path.startsWith('/admin/cicd') ||
      path === '/devops' ||
      path.startsWith('/devops') ||
      path === '/admin/devops' ||
      path.startsWith('/admin/devops')
    );
  },

  isRoleAdminPath(path: string): boolean {
    return (
      path === '/admin/roles' ||
      path === '/admin/rbac' ||
      path === '/admin/phan-quyen' ||
      path.startsWith('/admin/roles') ||
      path.startsWith('/admin/rbac')
    );
  },

  isAccountProvisioningPath(path: string): boolean {
    return (
      path === '/quan-ly/account-provisioning' ||
      path === '/quan-ly/cap-phat-tai-khoan' ||
      path.startsWith('/quan-ly/account-provisioning') ||
      path.startsWith('/quan-ly/cap-phat-tai-khoan') ||
      path === '/admin/account-provisioning' ||
      path === '/admin/cap-phat-tai-khoan' ||
      path.startsWith('/admin/account-provisioning')
    );
  },

  isVehiclePath(path: string): boolean {
    return (
      path === '/quan-ly/vehicles' ||
      path === '/quan-ly/phuong-tien' ||
      path.startsWith('/quan-ly/vehicles') ||
      path === '/admin/vehicles' ||
      path === '/admin/phuong-tien' ||
      path.startsWith('/admin/vehicles')
    );
  },

  isSecurityPath(path: string): boolean {
    return (
      path === '/an-ninh' ||
      path.startsWith('/an-ninh') ||
      path === '/bao-ve' ||
      path.startsWith('/bao-ve') ||
      path === '/security' ||
      path.startsWith('/security')
    );
  },

  isReceptionistVehiclePath(path: string): boolean {
    return (
      path === '/le-tan/vehicles' ||
      path === '/le-tan/phuong-tien' ||
      path.startsWith('/le-tan/vehicles') ||
      path.startsWith('/le-tan/phuong-tien') ||
      path === '/an-ninh/vehicles' ||
      path === '/an-ninh/phuong-tien' ||
      path.startsWith('/an-ninh/vehicles')
    );
  },

  matchRoute(
    currentPath: string,
    currentUser: UserSession | null,
    searchParams: URLSearchParams = new URLSearchParams(window.location.search)
  ): RouteMatch {
    // 0. Public Status
    if (currentPath === '/status' || currentPath === '/status/') {
      return { category: 'public_status' };
    }
    if (currentPath === '/status/incidents' || currentPath.startsWith('/status/incidents')) {
      return { category: 'status_incidents' };
    }

    // 0.1 Kích hoạt tài khoản
    if (
      currentPath === '/kich-hoat-tai-khoan' ||
      currentPath.startsWith('/kich-hoat-tai-khoan') ||
      currentPath === '/activate-account' ||
      currentPath.startsWith('/activate-account')
    ) {
      return { category: 'account_activation' };
    }

    // 0.2 Dev login redirect
    const isDevLoginPath =
      currentPath === '/dev/login' ||
      currentPath === '/admin/login' ||
      currentPath === '/dev/dang-nhap' ||
      currentPath === '/admin/dang-nhap';
    if (isDevLoginPath) {
      return { category: 'dev_login_redirect', redirect: '/login' };
    }

    const amenityPath = this.isAmenityPath(currentPath);
    const cicdPath = this.isCicdAdminPath(currentPath);
    const rolePath = this.isRoleAdminPath(currentPath);
    const accountProvisioningPath = this.isAccountProvisioningPath(currentPath);
    const vehiclePath = this.isVehiclePath(currentPath);

    // 1. Admin & Dev unified portal
    const isUnifiedAdminDevPath =
      !amenityPath &&
      !accountProvisioningPath &&
      !vehiclePath &&
      (currentPath === '/admin' ||
        currentPath.startsWith('/admin/') ||
        currentPath === '/dev' ||
        currentPath.startsWith('/dev/') ||
        currentPath === '/developer' ||
        currentPath.startsWith('/developer/') ||
        cicdPath ||
        rolePath);

    if (isUnifiedAdminDevPath) {
      if (currentUser && currentUser.role !== 'admin' && !currentUser.isDev) {
        if (currentUser.role === 'manager') return { category: 'admin_dev', redirect: '/quan-ly' };
        if (currentUser.role === 'receptionist') return { category: 'admin_dev', redirect: '/le-tan' };
        if (currentUser.role === 'resident') return { category: 'admin_dev', redirect: '/cu-dan' };
      }
      const urlTab = searchParams.get('tab');
      const tabToUse = cicdPath ? 'cicd' : rolePath ? 'roles' : (urlTab || 'overview');
      return { category: 'admin_dev', initialTab: tabToUse };
    }

    // 2. Manager portal
    const isManagerPath =
      currentPath === '/quan-ly' ||
      currentPath.startsWith('/quan-ly/') ||
      currentPath === '/manager' ||
      currentPath.startsWith('/manager/') ||
      currentPath === '/dashboard' ||
      amenityPath ||
      accountProvisioningPath ||
      vehiclePath;

    if (isManagerPath) {
      if (currentUser && currentUser.role !== 'manager' && currentUser.role !== 'admin') {
        if (currentUser.role === 'receptionist') return { category: 'manager', redirect: '/le-tan' };
        if (currentUser.role === 'resident') return { category: 'manager', redirect: '/cu-dan' };
      }
      const managerUrlTab = searchParams.get('tab');
      const tabForManager = amenityPath
        ? 'amenities'
        : vehiclePath
        ? 'vehicles'
        : accountProvisioningPath
        ? 'account_provisioning'
        : (managerUrlTab || undefined);
      return { category: 'manager', initialTab: tabForManager };
    }

    // 3. Resident portal
    const isExplicitLanding = searchParams.get('landing') === 'true';
    const isResidentSession =
      currentUser?.role === 'resident' || currentUser?.role?.toLowerCase().includes('resident');

    const isResidentPath =
      currentPath === '/cu-dan' ||
      currentPath.startsWith('/cu-dan') ||
      currentPath === '/resident' ||
      currentPath.startsWith('/resident') ||
      currentPath === '/resident-portal' ||
      (Boolean(isResidentSession) && (currentPath === '/' || currentPath === '' || currentPath === '/home') && !isExplicitLanding);

    if (isResidentPath) {
      return { category: 'resident' };
    }

    // 4. Receptionist & Security portal
    const isReceptionistSession =
      currentUser?.role === 'receptionist' ||
      currentUser?.role?.toLowerCase().includes('receptionist') ||
      currentUser?.role?.toLowerCase().includes('letan');

    const securityPath = this.isSecurityPath(currentPath);
    const receptionistVehiclePath = this.isReceptionistVehiclePath(currentPath);

    const isReceptionistPath =
      currentPath === '/le-tan' ||
      currentPath.startsWith('/le-tan') ||
      currentPath === '/receptionist' ||
      currentPath.startsWith('/receptionist') ||
      securityPath ||
      receptionistVehiclePath ||
      (Boolean(isReceptionistSession) && (currentPath === '/' || currentPath === '' || currentPath === '/home') && !isExplicitLanding);

    if (isReceptionistPath) {
      const receptionUrlTab = searchParams.get('tab');
      const initialReceptionTab = receptionistVehiclePath
        ? 'vehicle_reg'
        : (receptionUrlTab || undefined);
      return {
        category: 'receptionist',
        initialTab: initialReceptionTab,
        isSecurity: securityPath,
      };
    }

    // 5. Auth pages
    if (currentPath === '/login' || currentPath === '/dang-nhap') {
      return { category: 'login' };
    }
    if (currentPath === '/register' || currentPath === '/dang-ky') {
      return { category: 'register' };
    }
    if (
      currentPath === '/forgot-password' ||
      currentPath === '/quen-mat-khau' ||
      currentPath === '/verify-otp' ||
      currentPath === '/reset-password'
    ) {
      return { category: 'forgot_password' };
    }

    // 6. Home
    const isHomePage =
      currentPath === '/' ||
      currentPath === '' ||
      currentPath === '/home' ||
      currentPath.startsWith('/home');

    if (isHomePage) {
      return { category: 'home' };
    }

    return { category: 'not_found' };
  },
};
