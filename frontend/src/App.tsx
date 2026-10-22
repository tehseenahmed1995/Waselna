import { useState } from 'react';
import { AuthScreen } from './features/auth/AuthScreen';
import { LandingPage } from './features/landing/LandingPage';
import type { RegisterRole } from './features/auth/useAuth';
import { useAccessToken, useCurrentUser, useLogout } from './features/auth/useAuth';
import { RestaurantList } from './features/catalog/RestaurantList';
import { CartPanel } from './features/cart/CartPanel';
import { OrdersView } from './features/orders/OrdersView';
import { OperatorDashboard } from './features/restaurant/OperatorDashboard';
import { CourierDashboard } from './features/courier/CourierDashboard';
import { AdminDashboard } from './features/admin/AdminDashboard';
import { NotificationBell } from './features/notifications/NotificationBell';
import { useCart } from './features/cart/useCart';
import { useEnums } from './shared/enums';

type Tab = 'browse' | 'orders';

export function App() {
  const token = useAccessToken();
  const me = useCurrentUser();
  const logout = useLogout();
  const [tab, setTab] = useState<Tab>('browse');
  const [focusOrderId, setFocusOrderId] = useState<string | null>(null);
  const [gateView, setGateView] = useState<'landing' | 'auth'>('landing');
  const [authMode, setAuthMode] = useState<'login' | 'register'>('login');
  const [authRole, setAuthRole] = useState<RegisterRole>('customer');
  const cart = useCart();
  const { roleLabel } = useEnums();

  if (token === null || me.isError) {
    if (gateView === 'landing') {
      return (
        <LandingPage
          onChoose={(r) => {
            setAuthRole(r);
            setAuthMode('register');
            setGateView('auth');
          }}
          onSignIn={() => {
            setAuthMode('login');
            setGateView('auth');
          }}
        />
      );
    }
    return (
      <AuthScreen
        initialMode={authMode}
        initialRole={authRole}
        onBack={() => setGateView('landing')}
      />
    );
  }

  if (me.isLoading) {
    return (
      <div className="empty" style={{ marginTop: '20vh' }}>
        <span className="spinner" /> Loading…
      </div>
    );
  }

  const role = me.data?.role ?? 'customer';
  const isCustomer = role === 'customer';
  const cartCount = cart.data?.lines.reduce((n, l) => n + l.quantity, 0) ?? 0;

  const displayName = me.data?.display_name ?? me.data?.email ?? 'Account';
  const initials = displayName
    .split(/[\s@._-]+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase() ?? '')
    .join('') || 'U';

  const onOrderPlaced = (orderId: string) => {
    setFocusOrderId(orderId);
    setTab('orders');
  };

  return (
    <div className="app-shell">
      <header className="topbar">
        <div className="brand">
          <span className="brand-mark">W</span>
          <span>Waselna</span>
        </div>
        <div className="topbar-right">
          {isCustomer && (
            <>
              <button
                className={`btn btn-sm ${tab === 'browse' ? 'btn-primary' : 'btn-ghost'}`}
                onClick={() => {
                  setTab('browse');
                  setFocusOrderId(null);
                }}
              >
                Browse
              </button>
              <button
                className={`btn btn-sm ${tab === 'orders' ? 'btn-primary' : 'btn-ghost'}`}
                onClick={() => setTab('orders')}
              >
                Orders
              </button>
            </>
          )}
          <NotificationBell />
          <div className="user-menu">
            <div className="user-avatar" aria-hidden="true">{initials}</div>
            <div className="user-chip" title={me.data?.email ?? undefined}>
              <span className="user-chip-name">{displayName}</span>
              <span className="user-chip-role">{roleLabel(role)}</span>
            </div>
            <button
              className="signout-btn"
              onClick={() => logout.mutate()}
              disabled={logout.isPending}
              title="Sign out"
              aria-label="Sign out"
            >
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
                <polyline points="10 17 15 12 10 7" />
                <line x1="15" y1="12" x2="3" y2="12" />
              </svg>
            </button>
          </div>
        </div>
      </header>

      <main className="container">
        {(role === 'restaurant_operator' || role === 'restaurant_staff') && <OperatorDashboard />}

        {role === 'courier' && <CourierDashboard />}

        {role === 'administrator' && <AdminDashboard />}

        {isCustomer && tab === 'browse' && (
          <div className="page-grid">
            <RestaurantList />
            <aside>
              <div style={{ position: 'sticky', top: 84 }}>
                <CartPanel onOrderPlaced={onOrderPlaced} />
                {cartCount > 0 && <p className="subtle mt-2" style={{ textAlign: 'center' }}>{cartCount} item(s) in cart</p>}
              </div>
            </aside>
          </div>
        )}

        {isCustomer && tab === 'orders' && (
          <>
            {focusOrderId && (
              <button className="btn btn-ghost btn-sm mt-2" style={{ marginBottom: 16 }} onClick={() => setFocusOrderId(null)}>
                ← All orders
              </button>
            )}
            <OrdersView focusOrderId={focusOrderId} />
          </>
        )}
      </main>
    </div>
  );
}
