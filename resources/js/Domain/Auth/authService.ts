import {
  UserSession,
  isDevUser,
  normalizeRole,
  resolveDefaultCredentials,
  resolveDefaultRouteForRole,
} from './roleResolver';

const SESSION_STORAGE_KEY = 'smartcassavas_session';
const ACTIVE_TAB_KEY = 'smartcassavas_active_admin_tab';

export const AuthService = {
  getSession(): UserSession | null {
    try {
      const saved = localStorage.getItem(SESSION_STORAGE_KEY);
      return saved ? JSON.parse(saved) : null;
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
    const defaults = resolveDefaultCredentials(normalizedRole, isDev, residentType);

    const session: UserSession = {
      role: normalizedRole,
      resident_type: (residentType === 'TENANT' ? 'TENANT' : 'OWNER'),
      isDev,
      email: email || defaults.email,
      name: defaults.name,
    };

    this.saveSession(session);
    return session;
  },

  getRedirectRoute(session: UserSession): string {
    return resolveDefaultRouteForRole(session);
  },
};
