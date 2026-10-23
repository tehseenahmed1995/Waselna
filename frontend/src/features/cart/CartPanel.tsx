import { useState } from 'react';
import { useCart, useSetLineQuantity, useRemoveLine, money } from './useCart';
import { useCheckout } from '../orders/useOrders';

export function CartPanel({ onOrderPlaced }: { onOrderPlaced: (orderId: string) => void }) {
  const cart = useCart();
  const setQty = useSetLineQuantity();
  const removeLine = useRemoveLine();
  const checkout = useCheckout();

  const [line1, setLine1] = useState('1 Palm Avenue');
  const [city, setCity] = useState('Riyadh');

  const lines = cart.data?.lines ?? [];
  const empty = lines.length === 0;

  const placeOrder = () => {
    // Cash on delivery: placing the order confirms it immediately and sends it
    // to the restaurant. Payment is collected in cash on delivery.
    checkout.mutate(
      { address: { line1, city }, notes: undefined },
      { onSuccess: (order) => onOrderPlaced(order.id) },
    );
  };

  return (
    <div className="card">
      <div className="card-body">
        <h2>Your cart</h2>

        {cart.isLoading && <div className="empty"><span className="spinner" /></div>}

        {!cart.isLoading && empty && <div className="empty">Your cart is empty. Add something tasty.</div>}

        {!empty && (
          <>
            <div className="stack" style={{ gap: 10 }}>
              {lines.map((l) => (
                <div className="row-between" key={l.id}>
                  <div className="row-main">
                    <strong style={{ fontSize: 14 }}>{l.name}</strong>
                    <span className="subtle">{money(l.unit_price)} each</span>
                  </div>
                  <div className="qty">
                    <button
                      aria-label="decrease"
                      onClick={() => setQty.mutate({ lineId: l.id, quantity: l.quantity - 1 })}
                    >
                      −
                    </button>
                    <span>{l.quantity}</span>
                    <button
                      aria-label="increase"
                      onClick={() => setQty.mutate({ lineId: l.id, quantity: l.quantity + 1 })}
                    >
                      +
                    </button>
                    <button className="btn btn-ghost btn-sm" onClick={() => removeLine.mutate(l.id)}>
                      ✕
                    </button>
                  </div>
                </div>
              ))}
            </div>

            <div className="summary-row total mt-4">
              <span>Subtotal</span>
              <span>{money(cart.data?.line_subtotal)}</span>
            </div>

            <div className="mt-4">
              <div className="field">
                <label htmlFor="line1">Delivery address</label>
                <input id="line1" className="input" value={line1} onChange={(e) => setLine1(e.target.value)} />
              </div>
              <div className="field">
                <label htmlFor="city">City</label>
                <input id="city" className="input" value={city} onChange={(e) => setCity(e.target.value)} />
              </div>

              <div className="field">
                <label>Payment method</label>
                <div className="pay-method pay-method-selected">
                  <span className="pay-method-icon" aria-hidden="true">💵</span>
                  <div>
                    <strong>Cash on delivery</strong>
                    <div className="subtle" style={{ fontSize: 12 }}>Pay the courier in cash when your order arrives.</div>
                  </div>
                </div>
                <p className="subtle" style={{ fontSize: 12, marginTop: 6 }}>Card payment is coming soon.</p>
              </div>

              <button className="btn btn-primary btn-block" disabled={checkout.isPending} onClick={placeOrder}>
                {checkout.isPending ? 'Placing order…' : `Place order (cash) · ${money(cart.data?.line_subtotal)}`}
              </button>
              {checkout.isError && (
                <div className="alert alert-error">Could not place the order. Please try again.</div>
              )}
            </div>
          </>
        )}
      </div>
    </div>
  );
}
