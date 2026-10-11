import { useState } from 'react';
import { ApiRequestError } from '../../api/client';
import { useLogin, useRegister } from './useAuth';

export function AuthScreen() {
  const [mode, setMode] = useState<'login' | 'register'>('login');
  const [email, setEmail] = useState('customer@waselna.local');
  const [password, setPassword] = useState('local-seed-password');
  const [displayName, setDisplayName] = useState('New Customer');

  const login = useLogin();
  const register = useRegister();

  const active = mode === 'login' ? login : register;
  const error = active.error instanceof ApiRequestError ? active.error.error : null;
  const fieldError = (name: string) =>
    error?.fields?.find((f) => f.field === name)?.rule ?? null;

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (mode === 'login') {
      login.mutate({ email, password });
    } else {
      register.mutate(
        { email, password, display_name: displayName },
        { onSuccess: () => setMode('login') },
      );
    }
  };

  return (
    <div className="auth-wrap">
      <div className="auth-card">
        <div className="auth-head">
          <div className="brand" style={{ justifyContent: 'center' }}>
            <span className="brand-mark">W</span>
            <span>Waselna</span>
          </div>
          <p className="subtle mt-2">Food delivery, done right.</p>
        </div>

        <div className="tabs" role="tablist">
          <button
            className={`tab ${mode === 'login' ? 'active' : ''}`}
            onClick={() => setMode('login')}
            role="tab"
            aria-selected={mode === 'login'}
          >
            Sign in
          </button>
          <button
            className={`tab ${mode === 'register' ? 'active' : ''}`}
            onClick={() => setMode('register')}
            role="tab"
            aria-selected={mode === 'register'}
          >
            Register
          </button>
        </div>

        <form onSubmit={submit}>
          {mode === 'register' && (
            <div className="field">
              <label htmlFor="displayName">Display name</label>
              <input
                id="displayName"
                className="input"
                value={displayName}
                onChange={(e) => setDisplayName(e.target.value)}
              />
              {fieldError('display_name') && (
                <span className="field-error">{fieldError('display_name')}</span>
              )}
            </div>
          )}
          <div className="field">
            <label htmlFor="email">Email</label>
            <input
              id="email"
              className="input"
              type="email"
              autoComplete="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
            />
            {fieldError('email') && <span className="field-error">{fieldError('email')}</span>}
          </div>
          <div className="field">
            <label htmlFor="password">Password</label>
            <input
              id="password"
              className="input"
              type="password"
              autoComplete={mode === 'login' ? 'current-password' : 'new-password'}
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
            {fieldError('password') && (
              <span className="field-error">{fieldError('password')}</span>
            )}
          </div>

          <button
            type="submit"
            className="btn btn-primary btn-block mt-2"
            disabled={active.isPending}
          >
            {active.isPending ? 'Working…' : mode === 'login' ? 'Sign in' : 'Create account'}
          </button>
        </form>

        {error && (
          <div className="alert alert-error">
            {error.message}
          </div>
        )}
        {mode === 'register' && register.isSuccess && (
          <div className="alert alert-success">Account created. You can sign in now.</div>
        )}
      </div>
    </div>
  );
}
