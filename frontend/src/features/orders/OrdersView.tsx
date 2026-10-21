import { useState } from 'react';
import { useOrder, useOrders, usePay } from './useOrders';
import { useSubmitReview } from './useReview';
import { money } from '../cart/useCart';

const PAYMENT_LABELS: Record<string, string> = {
  none: 'Not started',
  pending: 'Pending',
  authorized: 'Authorized',
  captured: 'Paid',
  declined: 'Declined',
  failed: 'Failed',
  refunded: 'Refunded',
};

const STATUS_LABELS: Record<string, string> = {
  pending_payment: 'Awaiting payment',
  confirmed: 'Confirmed',
  preparing: 'Preparing',
  ready_for_pickup: 'Ready for pickup',
  assigned_to_rider: 'Rider assigned',
  picked_up: 'Picked up',
  out_for_delivery: 'Out for delivery',
  delivered: 'Delivered',
  cancelled: 'Cancelled',
  rejected: 'Rejected',
  failed: 'Failed',
  payment_failed: 'Payment failed',
};

export function OrdersView({ focusOrderId }: { focusOrderId: string | null }) {
  const orders = useOrders();
  const focused = useOrder(focusOrderId);
  const pay = usePay();
  const review = useSubmitReview();
  const [rating, setRating] = useState(5);
  const [comment, setComment] = useState('');

  if (focusOrderId && focused.data) {
    const o = focused.data;
    const payment = o.payment;
    const awaitingPayment = o.status === 'pending_payment';
    const paid = payment?.status === 'captured';
    const delivered = o.status === 'delivered';

    return (
      <div className="stack">
        <div className="card">
          <div className="card-body">
            <div className="row-between">
              <h2 style={{ margin: 0 }}>Order {o.order_number}</h2>
              <span className="badge badge-active">{STATUS_LABELS[o.status] ?? o.status}</span>
            </div>

            <div className="mt-4">
              {o.lines.map((l) => (
                <div className="summary-row" key={l.id}>
                  <span>
                    {l.quantity} × {l.snapshot?.name ?? 'Item'}
                  </span>
                  <span>{money(l.subtotal)}</span>
                </div>
              ))}
              <div className="summary-row"><span>Delivery fee</span><span>{money(o.breakdown.delivery_fee)}</span></div>
              <div className="summary-row"><span>Service fee</span><span>{money(o.breakdown.service_fee)}</span></div>
              <div className="summary-row"><span>Tax</span><span>{money(o.breakdown.tax_amount)}</span></div>
              <div className="summary-row total"><span>Total</span><span>{money(o.breakdown.order_total)}</span></div>
            </div>
          </div>
        </div>

        {/* Payment card — the explicit Payment module step */}
        <div className="card">
          <div className="card-body">
            <div className="row-between">
              <h3>Payment</h3>
              <span className={`badge ${paid ? 'badge-active' : ''}`}>
                {PAYMENT_LABELS[payment?.status ?? 'none'] ?? payment?.status}
              </span>
            </div>

            {awaitingPayment && (
              <div className="mt-4">
                <p className="subtle" style={{ marginTop: 0 }}>
                  Pay {money(o.breakdown.order_total)} with the simulated gateway to confirm your order.
                </p>
                <button
                  className="btn btn-primary btn-block"
                  disabled={pay.isPending}
                  onClick={() => pay.mutate(o.id)}
                >
                  {pay.isPending ? 'Processing payment…' : `Pay ${money(o.breakdown.order_total)}`}
                </button>
                {pay.isError && (
                  <div className="alert alert-error">Payment could not be processed. Try again.</div>
                )}
              </div>
            )}

            {paid && (
              <div className="alert alert-success mt-2">
                Payment captured{payment?.transaction_id ? ` · ${payment.transaction_id}` : ''}
              </div>
            )}

            {!awaitingPayment && !paid && payment && payment.status !== 'none' && (
              <div className="alert alert-error mt-2">Payment {PAYMENT_LABELS[payment.status] ?? payment.status}.</div>
            )}
          </div>
        </div>

        {delivered && (
          <div className="card">
            <div className="card-body">
              <h3>Rate your order</h3>
              {review.isSuccess ? (
                <div className="alert alert-success mt-2">Thanks for your review!</div>
              ) : (
                <div className="mt-2">
                  <div className="qty" style={{ marginBottom: 10 }}>
                    {[1, 2, 3, 4, 5].map((n) => (
                      <button
                        key={n}
                        onClick={() => setRating(n)}
                        style={{
                          background: n <= rating ? 'var(--primary)' : '#fff',
                          color: n <= rating ? '#fff' : 'var(--muted)',
                          width: 34,
                          height: 34,
                        }}
                        aria-label={`${n} star`}
                      >
                        ★
                      </button>
                    ))}
                  </div>
                  <div className="field">
                    <textarea
                      className="input"
                      rows={2}
                      placeholder="Optional comment"
                      value={comment}
                      onChange={(e) => setComment(e.target.value)}
                    />
                  </div>
                  <button
                    className="btn btn-primary"
                    disabled={review.isPending}
                    onClick={() => review.mutate({ orderId: o.id, rating, comment: comment || undefined })}
                  >
                    {review.isPending ? 'Submitting…' : 'Submit review'}
                  </button>
                  {review.isError && <div className="alert alert-error mt-2">Could not submit review.</div>}
                </div>
              )}
            </div>
          </div>
        )}

        {o.timeline && (
          <div className="card">
            <div className="card-body">
              <h3 style={{ marginBottom: 12 }}>Order progress</h3>
              <div className="stack" style={{ gap: 10 }}>
                {o.timeline.map((t, i) => (
                  <div className="row-between" key={i}>
                    <strong style={{ fontSize: 14 }}>{STATUS_LABELS[t.status] ?? t.status}</strong>
                    <span className="subtle">{new Date(t.at).toLocaleTimeString()}</span>
                  </div>
                ))}
              </div>
            </div>
          </div>
        )}
      </div>
    );
  }

  if (orders.isLoading) {
    return <div className="empty"><span className="spinner" /> Loading orders…</div>;
  }

  if ((orders.data?.length ?? 0) === 0) {
    return <div className="empty">No orders yet. Place your first one.</div>;
  }

  return (
    <div className="stack">
      <h2>Your orders</h2>
      {orders.data?.map((o) => (
        <div className="card" key={o.id}>
          <div className="card-body row-between">
            <div className="row-main">
              <strong>{o.order_number}</strong>
              <span className="subtle">{new Date(o.created_at).toLocaleString()}</span>
            </div>
            <div style={{ textAlign: 'right' }}>
              <div className="price">{money(o.breakdown.order_total)}</div>
              <span className="badge">{STATUS_LABELS[o.status] ?? o.status}</span>
            </div>
          </div>
        </div>
      ))}
    </div>
  );
}
