import { useState } from 'react';
import { ApiRequestError } from '../../api/client';
import { useLogin, useRegister, type RegisterRole } from './useAuth';

const ROLE_COPY: Record<RegisterRole, { title: string; blurb: string; cta: string }> = {
  customer: { title: 'Create your account', blurb: 'Order from restaurants near you.', cta: 'Create account' },
  restaurant_operator: { title: 'List your restaurant', blurb: 'Set up your restaurant and start taking orders.', cta: 'Create restaurant account' },
  courier: { title: 'Become a courier', blurb: 'Deliver orders and earn on your schedule.', cta: 'Create courier account' },
};

export function AuthScreen({
  initialMode = 'login',
  initialRole = 'customer',
  onBack,
}: {
  initialMode?: 'login' | 'register';
  initialRole?: RegisterRole;
  onBack?: () => void;
}) {
  const [mode, setMode] = useState<'login' | 'register'>(initialMode);
  const [role, setRole] = useState<RegisterRole>(initialRole);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [displayName, setDisplayName] = useState('');
  const [restaurantName, setRestaurantName] = useState('');

  const login = useLogin();
  const register = useRegister();

  const active = mode === 'login' ? login : register;
  const error = active.error instanceof ApiRequestError ? active.error.error : null;
  const fieldError = (name: string) => error?.fields?.find((f) => f.field === name)?.rule ?? null;

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (mode === 'login') {
      login.mutate({ email, password });
    } else {
      register.mutate(
        {
          email,
          password,
          display_name: displayName,
          role,
          restaurant_name: role === 'restaurant_operator' ? restaurantName : undefined,
        },
        { onSuccess: () => setMode('login') },
      );
    }
  };

  const copy = ROLE_COPY[role];

  return (
    <div className="auth-wrap">
      <div className="auth-card">
        <div className="auth-head">
          <div className="brand" style={{ justifyContent: 'center' }}>
            <span className="brand-mark">W</span>
            <span>Waselna</span>
          </div>
          <p className="subtle mt-2">{mode === 'register' ? copy.blurb : 'Welcome back.'}</p>
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
            <>
              <div className="field">
                <label htmlFor="reg-role">I want to join as</label>
                <select
                  id="reg-role"
                  className="input"
                  value={role}
                  onChange={(e) => setRole(e.target.value as RegisterRole)}
                >
                  <option value="customer">Customer — order food</option>
                  <option value="restaurant_operator">Restaurant — take orders</option>
                  <option value="courier">Courier — deliver orders</option>
                </select>
              </div>
              <div className="field">
                <label htmlFor="displayName">{role === 'restaurant_operator' ? 'Your name' : 'Display name'}</label>
                <input
                  id="displayName"
                  className="input"
                  value={displayName}
                  onChange={(e) => setDisplayName(e.target.value)}
                />
                {fieldError('display_name') && <span className="field-error">{fieldError('display_name')}</span>}
              </div>
              {role === 'restaurant_operator' && (
                <div className="field">
                  <label htmlFor="restaurantName">Restaurant name</label>
                  <input
                    id="restaurantName"
                    className="input"
                    value={restaurantName}
                    onChange={(e) => setRestaurantName(e.target.value)}
                    placeholder="e.g. Bella Napoli"
                  />
                  {fieldError('restaurant_name') && <span className="field-error">{fieldError('restaurant_name')}</span>}
                </div>
              )}
            </>
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
            {fieldError('password') && <span className="field-error">{fieldError('password')}</span>}
          </div>

          <button type="submit" className="btn btn-primary btn-block mt-2" disabled={active.isPending}>
            {active.isPending ? 'Working…' : mode === 'login' ? 'Sign in' : copy.cta}
          </button>
        </form>

        {error && <div className="alert alert-error">{error.message}</div>}
        {mode === 'register' && register.isSuccess && (
          <div className="alert alert-success">Account created. You can sign in now.</div>
        )}

        {onBack && (
          <button className="btn btn-ghost btn-block mt-2" onClick={onBack}>
            ← Back to home
          </button>
        )}
      </div>
    </div>
  );
}