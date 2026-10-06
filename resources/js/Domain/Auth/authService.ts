import {
  UserSession,
  isDevUser,
  normalizeRole,
  resolveDefaultRouteForRole,
} from './roleResolver';
import { api } from '../../Services/api';

const SESSION_STORAGE_KEY = 'smartcassavas_session';
const ACTIVE_TAB_KEY = 'smartcassavas_active_admin_tab';

export const AuthService = {
  getSession(): UserSession | null {
    try {
      const saved = localStorage.getItem(SESSION_STORAGE_KEY);
      if (!saved) return null;
      const session = JSON.parse(saved) as UserSession;
      const user = api.getUser();
      return user?.email === session.email
        ? { ...session, name: user.full_name || user.username || session.name }
        : session;
    } catch {
      return null;
    }
  },

  saveSession(session: UserSession): void {
    try {
      localStorage.setItem(SESSION_STORAGE_KEY, JSON.stringify(session));
      sessionStorage.removeItem(ACTIVE_TAB_KEY);
      localStorage.removeItem(ACTIVE_TAB_KEY);
    } catch {
      // ignore storage errors
    }
  },

  clearSession(): void {
    api.logout();
    try {
      localStorage.removeItem(SESSION_STORAGE_KEY);
      sessionStorage.removeItem(ACTIVE_TAB_KEY);
      localStorage.removeItem(ACTIVE_TAB_KEY);
    } catch {
      // ignore storage errors
    }
  },

  createSession(role: string, email: string, residentType?: string): UserSession {
    const isDev = isDevUser(email);
    const normalizedRole = normalizeRole(role, isDev);
    const user = api.getUser();

    const session: UserSession = {
      role: normalizedRole,
      resident_type: ((residentType || user?.resident_type) === 'TENANT' ? 'TENANT' : 'OWNER'),
      isDev,
      email: user?.email || email,
      name: user?.full_name || user?.username || email || 'Người dùng',
    };

    this.saveSession(session);
    return session;
  },

  getRedirectRoute(session: UserSession): string {
    return resolveDefaultRouteForRole(session);
  },
};
