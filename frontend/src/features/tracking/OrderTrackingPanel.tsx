import { useEnums } from '../../shared/enums';
import { TrackingMap } from './TrackingMap';
import { useOrderTracking, isTrackable } from './useTracking';

/**
 * Live tracking panel for an order: shows a map with pickup, dropoff, and the
 * courier's live position once a courier is assigned. Polls every few seconds.
 * Renders nothing until the order reaches a trackable (out-for-delivery) stage.
 */
export function OrderTrackingPanel({ orderId, status }: { orderId: string; status: string }) {
  const trackable = isTrackable(status);
  const tracking = useOrderTracking(orderId, trackable);
  const { orderStatusLabel } = useEnums();

  if (!trackable) return null;

  const t = tracking.data;

  return (
    <div className="card">
      <div className="card-body">
        <div className="row-between">
          <h3 style={{ margin: 0 }}>Live tracking</h3>
          {t && <span className="badge badge-active">{orderStatusLabel(t.status)}</span>}
        </div>

        {!t && <div className="empty"><span className="spinner" /> Loading map…</div>}

        {t && (
          <>
            <div className="mt-2">
              <TrackingMap
                pickup={t.pickup}
                dropoff={t.dropoff}
                courier={t.courier_location}
              />
            </div>
            <p className="subtle mt-2" style={{ marginBottom: 0 }}>
              {t.courier_location
                ? `Courier location updated ${t.courier_location.updated_at ? new Date(t.courier_location.updated_at).toLocaleTimeString() : 'just now'}.`
                : t.has_courier
                  ? 'Courier assigned. Waiting for their location…'
                  : 'Waiting for a courier to accept the delivery.'}
            </p>
          </>
        )}
      </div>
    </div>
  );
}