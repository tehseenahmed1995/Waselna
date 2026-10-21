import { useState } from 'react';
import { AuthScreen } from './features/auth/AuthScreen';
import { useAccessToken, useCurrentUser, useLogout } from './features/auth/useAuth';
import { RestaurantList } from './features/catalog/RestaurantList';
import { CartPanel } from './features/cart/CartPanel';
import { OrdersView } from './features/orders/OrdersView';
import { OperatorDashboard } from './features/restaurant/OperatorDashboard';
import { CourierDashboard } from './features/courier/CourierDashboard';
import { AdminDashboard } from './features/admin/AdminDashboard';
import { NotificationBell } from './features/notifications/NotificationBell';
import { useCart } from './features/cart/useCart';

type Tab = 'browse' | 'orders';

export function App() {
  const token = useAccessToken();
  const me = useCurrentUser();
  const logout = useLogout();
  const [tab, setTab] = useState<Tab>('browse');
  const [focusOrderId, setFocusOrderId] = useState<string | null>(null);
  const cart = useCart();

  if (token === null || me.isError) {
    return <AuthScreen />;
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
          <span>{me.data?.role}</span>
          <button className="btn btn-ghost btn-sm" onClick={() => logout.mutate()}>
            Sign out
          </button>
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
