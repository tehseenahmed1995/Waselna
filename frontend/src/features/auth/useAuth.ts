import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch, getAccessToken, setAccessToken, subscribeToken } from '../../api/client';

export interface AuthUser {
  id: string;
  email: string;
  display_name: string;
  role: string;
}

interface TokenPair {
  access_token: string;
  refresh_token: string;
  expires_at: string;
  user: AuthUser;
}

// Reactive access-token hook: re-renders the component whenever the token is
// set (login) or cleared (logout), so the app routes to the right screen
// immediately without a manual page refresh.
export function useAccessToken(): string | null {
  const [token, setToken] = useState<string | null>(getAccessToken());
  useEffect(() => subscribeToken(setToken), []);
  return token;
}

export function useCurrentUser() {
  const token = useAccessToken();
  return useQuery({
    queryKey: ['me'],
    queryFn: () =>
      apiFetch<{ id: string; role: string; account_status: string; display_name: string | null; email: string | null }>(
        '/auth/me',
      ),
    enabled: token !== null,
    retry: false,
  });
}

export function useLogin() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { email: string; password: string }) =>
      apiFetch<TokenPair>('/auth/login', { method: 'POST', body: input, auth: false }),
    onSuccess: async (data) => {
      // Setting the token notifies useAccessToken subscribers (re-render) and
      // enables the `me` query; refetch it so the role-based screen appears at
      // once.
      setAccessToken(data.access_token);
      await qc.invalidateQueries({ queryKey: ['me'] });
    },
  });
}

export type RegisterRole = 'customer' | 'restaurant_operator' | 'courier';

export interface RegisterInput {
  email: string;
  password: string;
  display_name: string;
  role?: RegisterRole;
  restaurant_name?: string;
}

export function useRegister() {
  return useMutation({
    mutationFn: (input: RegisterInput) =>
      apiFetch<AuthUser>('/auth/register', { method: 'POST', body: input, auth: false }),
  });
}

export function useLogout() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => apiFetch<unknown>('/auth/logout', { method: 'POST' }),
    onSettled: () => {
      setAccessToken(null);
      qc.clear();
    },
  });
}