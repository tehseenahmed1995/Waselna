import { useEffect, useState } from 'react';
import { money } from '../cart/useCart';
import { useEnums } from '../../shared/enums';
import {
  useAdminSummary,
  useAdminOrders,
  useAdminCouriers,
  useAdminRestaurants,
  useCreateRestaurant,
  useUpdateRestaurant,
  useDeleteRestaurant,
  useSetRestaurantVisibility,
  type AdminRestaurant,
  type NewRestaurant,
} from './useAdmin';

type Section = 'dashboard' | 'restaurants' | 'orders' | 'couriers';

const NAV: { key: Section; label: string; icon: string }[] = [
  { key: 'dashboard', label: 'Dashboard', icon: '▣' },
  { key: 'restaurants', label: 'Restaurants', icon: '🍽' },
  { key: 'orders', label: 'Orders', icon: '🧾' },
  { key: 'couriers', label: 'Couriers', icon: '🛵' },
];

export function AdminDashboard({
  focusRestaurantId = null,
  onFocusHandled,
}: {
  focusRestaurantId?: string | null;
  onFocusHandled?: () => void;
} = {}) {
  const [section, setSection] = useState<Section>('dashboard');

  // A notification deep-link: jump to the Restaurants section so the targeted
  // restaurant can be reviewed and published.
  useEffect(() => {
    if (focusRestaurantId) setSection('restaurants');
  }, [focusRestaurantId]);

  return (
    <div className="admin-shell">
      <nav className="admin-sidebar">
        {NAV.map((n) => (
          <button
            key={n.key}
            className={`admin-nav-item ${section === n.key ? 'active' : ''}`}
            onClick={() => setSection(n.key)}
          >
            <span className="admin-nav-icon">{n.icon}</span>
            {n.label}
          </button>
        ))}
      </nav>

      <div className="admin-content stack">
        {section === 'dashboard' && <DashboardSection />}
        {section === 'restaurants' && (
          <RestaurantsSection focusId={focusRestaurantId} onFocusHandled={onFocusHandled} />
        )}
        {section === 'orders' && <OrdersSection />}
        {section === 'couriers' && <CouriersSection />}
      </div>
    </div>
  );
}

function DashboardSection() {
  const summary = useAdminSummary();
  const s = summary.data;
  return (
    <div className="stack">
      <h1>Dashboard</h1>
      <div className="subtle" style={{ marginTop: -8 }}>Today</div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 16 }}>
        <Stat label="Orders today" value={s?.orders_today ?? '…'} />
        <Stat label="Revenue today" value={s ? money(s.gross_revenue_today) : '…'} />
        <Stat label="Active deliveries" value={s?.active_deliveries ?? '…'} />
      </div>
      <div className="subtle" style={{ marginTop: 4 }}>All time</div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 16 }}>
        <Stat label="Total orders" value={s?.orders_total ?? '…'} />
        <Stat label="Gross revenue" value={s ? money(s.gross_revenue) : '…'} />
        <Stat label="Restaurants" value={s?.restaurants ?? '…'} />
        <Stat label="Couriers" value={s?.couriers ?? '…'} />
      </div>
    </div>
  );
}

function OrdersSection() {
  const orders = useAdminOrders();
  const { orderStatusLabel } = useEnums();
  return (
    <div className="stack">
      <h1>Orders</h1>
      <div className="card">
        {(orders.data?.length ?? 0) === 0 && <div className="empty">No orders yet.</div>}
        {orders.data?.map((o) => (
          <div className="list-row" key={o.id}>
            <div className="row-main">
              <strong>{o.order_number}</strong>
              <span className="subtle">{o.line_count} item(s) · {new Date(o.created_at).toLocaleString()}</span>
            </div>
            <div style={{ textAlign: 'right' }}>
              <div className="price">{money(o.order_total)}</div>
              <span className="badge">{orderStatusLabel(o.status)}</span>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

function CouriersSection() {
  const couriers = useAdminCouriers();
  const { riderStatusLabel } = useEnums();
  return (
    <div className="stack">
      <h1>Couriers</h1>
      <div className="card">
        {(couriers.data?.length ?? 0) === 0 && <div className="empty">No couriers.</div>}
        {couriers.data?.map((c) => (
          <div className="list-row" key={c.id}>
            <div className="row-main">
              <strong>{c.display_name}</strong>
              <span className="subtle">{c.email} · {c.vehicle_type ?? 'no vehicle'}</span>
            </div>
            <span className={`badge ${c.rider_status === 'online' ? 'badge-active' : ''}`}>{riderStatusLabel(c.rider_status)}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

const EMPTY_FORM: NewRestaurant = { name: '', cuisine: '', address: '', currency: 'USD', tax_rate_bp: 0, base_delivery_fee: 0 };

function RestaurantsSection({
  focusId = null,
  onFocusHandled,
}: {
  focusId?: string | null;
  onFocusHandled?: () => void;
}) {
  const list = useAdminRestaurants();
  const { restaurantStatusLabel } = useEnums();
  const create = useCreateRestaurant();
  const update = useUpdateRestaurant();
  const del = useDeleteRestaurant();
  const visibility = useSetRestaurantVisibility();

  const [showCreate, setShowCreate] = useState(false);
  const [editing, setEditing] = useState<AdminRestaurant | null>(null);
  const [confirmDelete, setConfirmDelete] = useState<AdminRestaurant | null>(null);

  // Notification deep-link: once the list is loaded, open the targeted
  // restaurant so the admin can review and publish it.
  useEffect(() => {
    if (!focusId || !list.data) return;
    const target = list.data.find((r) => r.id === focusId);
    if (target) {
      setEditing(target);
      onFocusHandled?.();
    }
  }, [focusId, list.data, onFocusHandled]);

  return (
    <div className="stack">
      <div className="row-between">
        <h1>Restaurants</h1>
        <button className="btn btn-primary" onClick={() => setShowCreate(true)}>+ Add restaurant</button>
      </div>

      <div className="card">
        {(list.data?.length ?? 0) === 0 && <div className="empty">No restaurants yet.</div>}
        {list.data?.map((r) => {
          const isLive = r.is_discoverable ?? false;
          return (
            <div className="list-row" key={r.id}>
              <div className="row-main">
                <strong>{r.name}</strong>
                <span className="subtle">{r.menu_items ?? 0} item(s) · {r.currency}</span>
              </div>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <span className={`badge ${isLive ? 'badge-active' : ''}`}>{restaurantStatusLabel(r.status)}</span>
                <button className="btn btn-sm btn-ghost" onClick={() => setEditing(r)}>Edit</button>
                {isLive ? (
                  <button className="btn btn-sm btn-ghost" disabled={visibility.isPending}
                    onClick={() => visibility.mutate({ id: r.id, action: 'suspend' })}>Suspend</button>
                ) : (
                  <button className="btn btn-sm btn-primary" disabled={visibility.isPending}
                    onClick={() => visibility.mutate({ id: r.id, action: 'publish' })}>Publish</button>
                )}
                <button className="btn btn-sm btn-danger" onClick={() => setConfirmDelete(r)}>Delete</button>
              </div>
            </div>
          );
        })}
      </div>

      {showCreate && (
        <RestaurantFormModal
          title="Add restaurant"
          initial={EMPTY_FORM}
          submitting={create.isPending}
          onClose={() => setShowCreate(false)}
          onSubmit={(form) => create.mutate(form, { onSuccess: () => setShowCreate(false) })}
        />
      )}

      {editing && (
        <RestaurantFormModal
          title={`Review ${editing.name}`}
          initial={{
            name: editing.name,
            currency: editing.currency,
            tax_rate_bp: editing.tax_rate_bp ?? 0,
            base_delivery_fee: editing.base_delivery_fee ?? 0,
          }}
          submitting={update.isPending}
          hideCreateOnly
          headerExtra={
            <div className="review-banner">
              <div>
                <span className="subtle" style={{ fontSize: 12 }}>Current status</span>
                <div>
                  <span className={`badge ${(editing.is_discoverable ?? false) ? 'badge-active' : ''}`}>
                    {restaurantStatusLabel(editing.status)}
                  </span>
                </div>
              </div>
              {(editing.is_discoverable ?? false) ? (
                <button
                  className="btn btn-sm btn-ghost"
                  disabled={visibility.isPending}
                  onClick={() => visibility.mutate({ id: editing.id, action: 'suspend' }, { onSuccess: () => setEditing(null) })}
                >
                  Suspend
                </button>
              ) : (
                <button
                  className="btn btn-sm btn-primary"
                  disabled={visibility.isPending}
                  onClick={() => visibility.mutate({ id: editing.id, action: 'publish' }, { onSuccess: () => setEditing(null) })}
                >
                  Publish &amp; activate
                </button>
              )}
            </div>
          }
          onClose={() => setEditing(null)}
          onSubmit={(form) =>
            update.mutate(
              { id: editing.id, patch: { name: form.name, currency: form.currency, tax_rate_bp: form.tax_rate_bp, base_delivery_fee: form.base_delivery_fee } },
              { onSuccess: () => setEditing(null) },
            )
          }
        />
      )}

      {confirmDelete && (
        <div className="modal-backdrop" onClick={() => setConfirmDelete(null)}>
          <div className="modal-card" onClick={(e) => e.stopPropagation()}>
            <h3>Delete {confirmDelete.name}?</h3>
            <p className="subtle mt-2">This removes the restaurant, its branches, and its menu. This cannot be undone.</p>
            <div className="row-between mt-2" style={{ justifyContent: 'flex-end', gap: 10 }}>
              <button className="btn btn-ghost" onClick={() => setConfirmDelete(null)}>Cancel</button>
              <button className="btn btn-danger" disabled={del.isPending}
                onClick={() => del.mutate(confirmDelete.id, { onSuccess: () => setConfirmDelete(null) })}>
                {del.isPending ? 'Deleting…' : 'Delete'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function RestaurantFormModal(props: {
  title: string;
  initial: NewRestaurant;
  submitting: boolean;
  hideCreateOnly?: boolean;
  headerExtra?: React.ReactNode;
  onClose: () => void;
  onSubmit: (form: NewRestaurant) => void;
}) {
  const [form, setForm] = useState<NewRestaurant>(props.initial);
  const { currencyOptions } = useEnums();
  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!form.name.trim()) return;
    props.onSubmit(form);
  };
  return (
    <div className="modal-backdrop" onClick={props.onClose}>
      <div className="modal-card" onClick={(e) => e.stopPropagation()}>
        <h3>{props.title}</h3>
        {props.headerExtra}
        <form onSubmit={submit} className="stack" style={{ gap: 10, marginTop: 10 }}>
          <div className="field">
            <label htmlFor="rf-name">Name</label>
            <input id="rf-name" className="input" value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Pizza Palace" />
          </div>
          {!props.hideCreateOnly && (
            <>
              <div className="field">
                <label htmlFor="rf-cuisine">Cuisine</label>
                <input id="rf-cuisine" className="input" value={form.cuisine ?? ''}
                  onChange={(e) => setForm({ ...form, cuisine: e.target.value })} placeholder="Italian" />
              </div>
              <div className="field">
                <label htmlFor="rf-address">Address</label>
                <input id="rf-address" className="input" value={form.address ?? ''}
                  onChange={(e) => setForm({ ...form, address: e.target.value })} placeholder="1 Main St, Riyadh" />
              </div>
            </>
          )}
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
            <div className="field">
              <label htmlFor="rf-currency">Currency</label>
              <select id="rf-currency" className="input" value={form.currency ?? 'USD'}
                onChange={(e) => setForm({ ...form, currency: e.target.value })}>
                {currencyOptions.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
              </select>
            </div>
            <div className="field">
              <label htmlFor="rf-fee">Base delivery fee (minor)</label>
              <input id="rf-fee" className="input" type="number" min={0} value={form.base_delivery_fee ?? 0}
                onChange={(e) => setForm({ ...form, base_delivery_fee: Number(e.target.value) })} />
            </div>
          </div>
          <div className="field">
            <label htmlFor="rf-tax">Tax rate (basis points, 100 = 1%)</label>
            <input id="rf-tax" className="input" type="number" min={0} max={3000} value={form.tax_rate_bp ?? 0}
              onChange={(e) => setForm({ ...form, tax_rate_bp: Number(e.target.value) })} />
          </div>
          <div className="row-between" style={{ justifyContent: 'flex-end', gap: 10 }}>
            <button type="button" className="btn btn-ghost" onClick={props.onClose}>Cancel</button>
            <button type="submit" className="btn btn-primary" disabled={props.submitting || !form.name.trim()}>
              {props.submitting ? 'Saving…' : 'Save'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="card">
      <div className="card-body">
        <div className="subtle">{label}</div>
        <div style={{ fontSize: 22, fontWeight: 700, marginTop: 4 }}>{value}</div>
      </div>
    </div>
  );
}