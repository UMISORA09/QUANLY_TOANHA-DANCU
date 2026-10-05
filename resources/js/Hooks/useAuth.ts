import { useState, useCallback } from 'react';
import { UserSession } from '../Domain/Auth/roleResolver';
import { AuthService } from '../Domain/Auth/authService';

export interface UseAuthReturn {
  currentUser: UserSession | null;
  login: (role: string, email: string, residentType?: string) => UserSession;
  logout: () => void;
  isAuthenticated: boolean;
}

export const useAuth = (): UseAuthReturn => {
  const [currentUser, setCurrentUser] = useState<UserSession | null>(() => AuthService.getSession());

  const login = useCallback((role: string, email: string, residentType?: string): UserSession => {
    const session = AuthService.createSession(role, email, residentType);
    setCurrentUser(session);
    return session;
  }, []);

  const logout = useCallback(() => {
    AuthService.clearSession();
    setCurrentUser(null);
  }, []);

  return {
    currentUser,
    login,
    logout,
    isAuthenticated: currentUser !== null,
  };
};
