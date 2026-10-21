import { money } from '../cart/useCart';
import {
  useRiderProfile,
  useAvailableJobs,
  useActiveJob,
  useUpdateRiderProfile,
  useToggleOnline,
  useJobAction,
} from './useCourier';

export function CourierDashboard() {
  const profile = useRiderProfile();
  const active = useActiveJob();
  const online = profile.data?.rider_status === 'online';
  const available = useAvailableJobs(online && !active.data);

  const updateProfile = useUpdateRiderProfile();
  const toggle = useToggleOnline();
  const accept = useJobAction('accept');
  const pickup = useJobAction('pickup');
  const transit = useJobAction('transit');
  const deliver = useJobAction('deliver');

  const p = profile.data;
  const incomplete = !p?.vehicle_type || !p?.phone;

  return (
    <div className="stack">
      <h1>Courier</h1>

      {/* Status card */}
      <div className="card">
        <div className="card-body">
          <div className="row-between">
            <h3>Status</h3>
            <span className={`badge ${online ? 'badge-active' : ''}`}>{p?.rider_status ?? '…'}</span>
          </div>

          {incomplete && (
            <div className="mt-2">
              <p className="subtle" style={{ marginTop: 0 }}>Complete your profile to go online.</p>
              <button
                className="btn btn-ghost btn-sm"
                disabled={updateProfile.isPending}
                onClick={() => updateProfile.mutate({ vehicle_type: 'motorcycle', phone: '+966500000000' })}
              >
                Set motorcycle · +966500000000
              </button>
            </div>
          )}

          {!incomplete && (
            <div className="mt-2 row-between">
              <span className="subtle">{p?.vehicle_type} · {p?.phone}</span>
              <button
                className={`btn btn-sm ${online ? 'btn-ghost' : 'btn-primary'}`}
                disabled={toggle.isPending}
                onClick={() => toggle.mutate(!online)}
              >
                {online ? 'Go offline' : 'Go online'}
              </button>
            </div>
          )}
          {toggle.isError && <div className="alert alert-error mt-2">Could not change status (finish active delivery first).</div>}
        </div>
      </div>

      {/* Active job */}
      {active.data && (
        <div className="card">
          <div className="card-body">
            <div className="row-between">
              <h3>Active delivery</h3>
              <span className="badge badge-active">{active.data.status}</span>
            </div>
            <p className="subtle mt-2">
              {active.data.order_number} · {active.data.order_total ? money(active.data.order_total) : ''}
            </p>
            <div className="mt-2">
              {active.data.status === 'accepted' && (
                <button className="btn btn-primary btn-block" disabled={pickup.isPending} onClick={() => pickup.mutate(active.data!.id)}>
                  Confirm pickup
                </button>
              )}
              {active.data.status === 'picked_up' && (
                <button className="btn btn-primary btn-block" disabled={transit.isPending} onClick={() => transit.mutate(active.data!.id)}>
                  Start delivery
                </button>
              )}
              {active.data.status === 'in_transit' && (
                <button className="btn btn-primary btn-block" disabled={deliver.isPending} onClick={() => deliver.mutate(active.data!.id)}>
                  Mark delivered
                </button>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Available jobs */}
      {online && !active.data && (
        <div className="card">
          <div className="card-body">
            <h3>Available deliveries</h3>
            {(available.data?.length ?? 0) === 0 && <div className="empty">No deliveries waiting.</div>}
            {available.data?.map((j) => (
              <div className="list-row" key={j.id}>
                <div className="row-main">
                  <strong>{j.order_number}</strong>
                  <span className="subtle">{j.order_total ? money(j.order_total) : ''}</span>
                </div>
                <button className="btn btn-primary btn-sm" disabled={accept.isPending} onClick={() => accept.mutate(j.id)}>
                  Accept
                </button>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
