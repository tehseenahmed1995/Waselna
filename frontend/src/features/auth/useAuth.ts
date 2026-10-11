import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch, getAccessToken, setAccessToken } from '../../api/client';

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

export function useCurrentUser() {
  return useQuery({
    queryKey: ['me'],
    queryFn: () => apiFetch<{ id: string; role: string; account_status: string }>('/auth/me'),
    enabled: getAccessToken() !== null,
    retry: false,
  });
}

export function useLogin() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { email: string; password: string }) =>
      apiFetch<TokenPair>('/auth/login', { method: 'POST', body: input, auth: false }),
    onSuccess: (data) => {
      setAccessToken(data.access_token);
      qc.invalidateQueries({ queryKey: ['me'] });
    },
  });
}

export function useRegister() {
  return useMutation({
    mutationFn: (input: { email: string; password: string; display_name: string }) =>
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
