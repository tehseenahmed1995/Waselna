import { money } from '../cart/useCart';
import {
  useRestaurantQueue,
  useAcceptOrder,
  useReadyOrder,
  useRejectOrder,
} from './useRestaurantOrders';

const STATUS_LABELS: Record<string, string> = {
  confirmed: 'New',
  preparing: 'Preparing',
  ready_for_pickup: 'Ready',
  assigned_to_rider: 'Rider assigned',
  picked_up: 'Picked up',
  out_for_delivery: 'Out for delivery',
};

export function OperatorDashboard() {
  const queue = useRestaurantQueue();
  const accept = useAcceptOrder();
  const ready = useReadyOrder();
  const reject = useRejectOrder();

  return (
    <div className="stack">
      <div className="row-between">
        <h1>Kitchen queue</h1>
        <span className="subtle">Auto-refreshing</span>
      </div>

      {queue.isLoading && <div className="empty"><span className="spinner" /> Loading…</div>}
      {!queue.isLoading && (queue.data?.length ?? 0) === 0 && (
        <div className="empty">No active orders right now.</div>
      )}

      {queue.data?.map((o) => (
        <div className="card" key={o.id}>
          <div className="card-body">
            <div className="row-between">
              <div className="row-main">
                <strong>{o.order_number}</strong>
                <span className="subtle">{new Date(o.created_at).toLocaleTimeString()}</span>
              </div>
              <div style={{ textAlign: 'right' }}>
                <div className="price">{money(o.order_total)}</div>
                <span className="badge badge-active">{STATUS_LABELS[o.status] ?? o.status}</span>
              </div>
            </div>

            <div className="mt-2">
              {o.lines.map((l, i) => (
                <div className="summary-row" key={i}>
                  <span>{l.quantity} × {l.name}</span>
                </div>
              ))}
            </div>

            <div className="row-between mt-4" style={{ gap: 8 }}>
              {o.status === 'confirmed' && (
                <>
                  <button
                    className="btn btn-primary btn-sm"
                    disabled={accept.isPending}
                    onClick={() => accept.mutate({ orderId: o.id })}
                  >
                    Accept
                  </button>
                  <button
                    className="btn btn-ghost btn-sm"
                    disabled={reject.isPending}
                    onClick={() => reject.mutate({ orderId: o.id, reason: 'Kitchen unavailable' })}
                  >
                    Reject
                  </button>
                </>
              )}
              {o.status === 'preparing' && (
                <button
                  className="btn btn-primary btn-sm"
                  disabled={ready.isPending}
                  onClick={() => ready.mutate({ orderId: o.id })}
                >
                  Mark ready for pickup
                </button>
              )}
              {['ready_for_pickup', 'assigned_to_rider', 'picked_up', 'out_for_delivery'].includes(o.status) && (
                <span className="subtle">Awaiting courier / in delivery</span>
              )}
            </div>
          </div>
        </div>
      ))}
    </div>
  );
}
