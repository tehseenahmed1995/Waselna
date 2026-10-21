import { useState } from 'react';
import { money } from '../cart/useCart';
import { useAdminSummary, useAdminOrders, useAdminCouriers } from './useAdmin';

const STATUS_LABELS: Record<string, string> = {
  pending_payment: 'Awaiting payment',
  confirmed: 'Confirmed',
  preparing: 'Preparing',
  ready_for_pickup: 'Ready',
  assigned_to_rider: 'Assigned',
  picked_up: 'Picked up',
  out_for_delivery: 'Out for delivery',
  delivered: 'Delivered',
  cancelled: 'Cancelled',
  rejected: 'Rejected',
  failed: 'Failed',
  payment_failed: 'Payment failed',
};

type Tab = 'orders' | 'couriers';

export function AdminDashboard() {
  const summary = useAdminSummary();
  const orders = useAdminOrders();
  const couriers = useAdminCouriers();
  const [tab, setTab] = useState<Tab>('orders');
  const s = summary.data;

  return (
    <div className="stack">
      <h1>Admin overview</h1>

      {/* Stat cards */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 16 }}>
        <Stat label="Total orders" value={s?.orders_total ?? '…'} />
        <Stat label="Gross revenue" value={s ? money(s.gross_revenue) : '…'} />
        <Stat label="Restaurants" value={s?.restaurants ?? '…'} />
        <Stat label="Couriers" value={s?.couriers ?? '…'} />
        <Stat label="Active deliveries" value={s?.active_deliveries ?? '…'} />
      </div>

      <div className="tabs" style={{ maxWidth: 280 }}>
        <button className={`tab ${tab === 'orders' ? 'active' : ''}`} onClick={() => setTab('orders')}>Orders</button>
        <button className={`tab ${tab === 'couriers' ? 'active' : ''}`} onClick={() => setTab('couriers')}>Couriers</button>
      </div>

      {tab === 'orders' && (
        <div className="card">
          <div className="card-body" style={{ paddingBottom: 4 }}>
            <h3>All orders</h3>
          </div>
          {(orders.data?.length ?? 0) === 0 && <div className="empty">No orders yet.</div>}
          {orders.data?.map((o) => (
            <div className="list-row" key={o.id}>
              <div className="row-main">
                <strong>{o.order_number}</strong>
                <span className="subtle">{o.line_count} item(s) · {new Date(o.created_at).toLocaleString()}</span>
              </div>
              <div style={{ textAlign: 'right' }}>
                <div className="price">{money(o.order_total)}</div>
                <span className="badge">{STATUS_LABELS[o.status] ?? o.status}</span>
              </div>
            </div>
          ))}
        </div>
      )}

      {tab === 'couriers' && (
        <div className="card">
          <div className="card-body" style={{ paddingBottom: 4 }}>
            <h3>Couriers</h3>
          </div>
          {couriers.data?.map((c) => (
            <div className="list-row" key={c.id}>
              <div className="row-main">
                <strong>{c.display_name}</strong>
                <span className="subtle">{c.email} · {c.vehicle_type ?? 'no vehicle'}</span>
              </div>
              <span className={`badge ${c.rider_status === 'online' ? 'badge-active' : ''}`}>{c.rider_status}</span>
            </div>
          ))}
        </div>
      )}
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
