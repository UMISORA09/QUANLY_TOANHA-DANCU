export interface UserSession {
  role: string;
  resident_type?: 'OWNER' | 'TENANT';
  email: string;
  name: string;
  isDev?: boolean;
}

export type NormalizedRole = 'admin' | 'manager' | 'receptionist' | 'resident';

export const isDevUser = (email: string, pathname: string = window.location.pathname): boolean => {
  const lowerEmail = (email || '').toLowerCase();
  return (
    lowerEmail.includes('dev') ||
    lowerEmail === 'dev@cassavas.vn' ||
    lowerEmail === 'dev@smartcassavas.vn' ||
    pathname.startsWith('/dev')
  );
};

export const normalizeRole = (role: string, isDev: boolean = false): NormalizedRole => {
  const lowerRole = (role || '').toLowerCase();

  const isAdmin =
    lowerRole === 'admin' ||
    lowerRole === 'super_admin';

  if (isAdmin || isDev) {
    return 'admin';
  }

  const isManager =
    lowerRole === 'manager' ||
    lowerRole === 'building_manager';

  if (isManager) {
    return 'manager';
  }

  const isReceptionist =
    lowerRole === 'receptionist' ||
    lowerRole.includes('receptionist') ||
    lowerRole.includes('letan') ||
    lowerRole.includes('lễ tân');

  if (isReceptionist) {
    return 'receptionist';
  }

  return 'resident';
};

export const resolveDefaultCredentials = (
  role: NormalizedRole,
  isDev: boolean = false,
  residentType?: string
): { name: string; email: string } => {
  if (isDev) {
    return { name: 'Dev Team', email: 'dev@cassavas.vn' };
  }

  switch (role) {
    case 'admin':
      return { name: 'Admin Cassavas', email: 'admin@cassavas.vn' };
    case 'manager':
      return { name: 'Ban Quản Lý', email: 'quanly@cassavas.vn' };
    case 'receptionist':
      return { name: 'Lễ Tân Sảnh Chính', email: 'letan@cassavas.vn' };
    case 'resident':
    default:
      return {
        name: residentType === 'TENANT' ? 'Khách Thuê Căn Hộ' : 'Nguyễn Văn An',
        email: 'nguyenvanan@cassavas.vn',
      };
  }
};

export const resolveDefaultRouteForRole = (session: UserSession): string => {
  if (session.isDev || session.role === 'admin') {
    return '/admin';
  }
  if (session.role === 'manager') {
    return '/quan-ly';
  }
  if (session.role === 'receptionist') {
    return '/le-tan';
  }
  if (session.resident_type === 'TENANT') {
    return '/cu-dan?tab=rentals';
  }
  return '/cu-dan';
};
