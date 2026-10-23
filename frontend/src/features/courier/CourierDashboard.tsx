import { useState } from 'react';
import { money } from '../cart/useCart';
import { useEnums } from '../../shared/enums';
import {
  useRiderProfile,
  useAvailableJobs,
  useActiveJob,
  useUpdateRiderProfile,
  useToggleOnline,
  useJobAction,
  useCourierHistory,
  type CourierHistoryFilters,
} from './useCourier';

function formatAddress(addr: Record<string, unknown> | null): string {
  if (!addr) return 'Address unavailable';
  const parts = [addr.line1, addr.line2, addr.city].map((v) => (typeof v === 'string' ? v : '')).filter(Boolean);
  return parts.length > 0 ? parts.join(', ') : 'Address unavailable';
}

export function CourierDashboard() {
  const profile = useRiderProfile();
  const active = useActiveJob();
  const online = profile.data?.rider_status === 'online';
  const available = useAvailableJobs(online && !active.data);

  const toggle = useToggleOnline();
  const accept = useJobAction('accept');
  const pickup = useJobAction('pickup');
  const transit = useJobAction('transit');
  const deliver = useJobAction('deliver');

  const p = profile.data;
  const incomplete = !p?.vehicle_type || !p?.phone;
  const [tab, setTab] = useState<'deliver' | 'history' | 'profile'>('deliver');

  return (
    <div className="stack">
      <div className="row-between">
        <h1>Courier</h1>
      </div>
      <div className="tabs" role="tablist">
        <button className={`tab ${tab === 'deliver' ? 'active' : ''}`} onClick={() => setTab('deliver')}>Deliver</button>
        <button className={`tab ${tab === 'history' ? 'active' : ''}`} onClick={() => setTab('history')}>History</button>
        <button className={`tab ${tab === 'profile' ? 'active' : ''}`} onClick={() => setTab('profile')}>Profile</button>
      </div>

      {tab === 'deliver' && (<>
      {/* Status card */}
      <div className="card">
        <div className="card-body">
          <div className="row-between">
            <h3>Status</h3>
            <span className={`badge ${online ? 'badge-active' : ''}`}>{p?.rider_status ?? '…'}</span>
          </div>

          {incomplete && (
            <div className="mt-2">
              <p className="subtle" style={{ marginTop: 0 }}>Add your vehicle and phone in the Profile tab to go online.</p>
              <button className="btn btn-ghost btn-sm" onClick={() => setTab('profile')}>Go to profile</button>
            </div>
          )}

          {!incomplete && (
            <div className="mt-2 row-between">
              <span className="subtle">{vehicleIcon(p?.vehicle_type)} {p?.vehicle_type} · {p?.phone}</span>
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
            <p className="subtle mt-2" style={{ marginBottom: 4 }}>
              {active.data.order_number} · {active.data.order_total ? money(active.data.order_total) : ''} · {active.data.item_count} item(s)
            </p>
            <div className="delivery-detail">
              <div className="delivery-leg">
                <span className="delivery-leg-icon" aria-hidden="true">🏪</span>
                <div>
                  <div className="subtle" style={{ fontSize: 11 }}>Pick up from</div>
                  <strong>{active.data.restaurant_name ?? 'Restaurant'}</strong>
                </div>
              </div>
              <div className="delivery-leg">
                <span className="delivery-leg-icon" aria-hidden="true">📍</span>
                <div>
                  <div className="subtle" style={{ fontSize: 11 }}>Deliver to</div>
                  <strong>{formatAddress(active.data.delivery_address)}</strong>
                </div>
              </div>
            </div>
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
                  <strong>{j.order_number} · {j.order_total ? money(j.order_total) : ''}</strong>
                  <span className="subtle">🏪 {j.restaurant_name ?? 'Restaurant'} · {j.item_count} item(s)</span>
                  <span className="subtle">📍 {formatAddress(j.delivery_address)}</span>
                </div>
                <button className="btn btn-primary btn-sm" disabled={accept.isPending} onClick={() => accept.mutate(j.id)}>
                  Accept
                </button>
              </div>
            ))}
          </div>
        </div>
      )}
      </>)}

      {tab === 'history' && <HistorySection active={tab === 'history'} />}
      {tab === 'profile' && <ProfileSection />}
    </div>
  );
}

function HistorySection({ active }: { active: boolean }) {
  const { orderStatusLabel } = useEnums();
  const [filters, setFilters] = useState<CourierHistoryFilters>({});
  const history = useCourierHistory(filters, active);
  const jobs = history.data ?? [];

  const delivered = jobs.filter((j) => j.status === 'delivered');
  const value = delivered.reduce((sum, j) => sum + (j.order_total?.amount ?? 0), 0);
  const currency = delivered[0]?.order_total?.currency ?? 'USD';

  const update = (patch: Partial<CourierHistoryFilters>) => setFilters((f) => ({ ...f, ...patch }));

  // Only the delivery-job statuses are meaningful here.
  const JOB_STATUSES = ['delivered', 'in_transit', 'picked_up', 'accepted', 'failed', 'cancelled'];

  return (
    <div className="stack">
      <div className="card">
        <div className="card-body" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 12 }}>
          <div className="field">
            <label htmlFor="ch-status">Status</label>
            <select id="ch-status" className="input" value={filters.status ?? ''} onChange={(e) => update({ status: e.target.value || undefined })}>
              <option value="">All</option>
              {JOB_STATUSES.map((s) => <option key={s} value={s}>{jobStatusLabel(s)}</option>)}
            </select>
          </div>
          <div className="field">
            <label htmlFor="ch-from">From</label>
            <input id="ch-from" className="input" type="date" value={filters.from ?? ''} onChange={(e) => update({ from: e.target.value || undefined })} />
          </div>
          <div className="field">
            <label htmlFor="ch-to">To</label>
            <input id="ch-to" className="input" type="date" value={filters.to ?? ''} onChange={(e) => update({ to: e.target.value || undefined })} />
          </div>
        </div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 16 }}>
        <CourierStat label="Jobs" value={jobs.length} />
        <CourierStat label="Delivered" value={delivered.length} />
        <CourierStat label="Delivered value" value={money({ amount: value, currency })} />
      </div>

      <div className="card">
        <div className="card-body">
          {history.isLoading && <div className="empty"><span className="spinner" /> Loading…</div>}
          {!history.isLoading && jobs.length === 0 && <div className="empty">No deliveries match these filters.</div>}
          {jobs.map((j) => (
            <div className="list-row" key={j.id}>
              <div className="row-main">
                <strong>{j.order_number}</strong>
                <span className="subtle">🏪 {j.restaurant_name ?? 'Restaurant'} · 📍 {formatAddress(j.delivery_address)}</span>
                <span className="subtle" style={{ fontSize: 11 }}>
                  {j.delivered_at ? `Delivered ${new Date(j.delivered_at).toLocaleString()}` : (j.created_at ? new Date(j.created_at).toLocaleString() : '')}
                </span>
              </div>
              <div style={{ textAlign: 'right' }}>
                <div className="price">{j.order_total ? money(j.order_total) : ''}</div>
                <span className="badge">{jobStatusLabel(j.status)}</span>
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );

  // Delivery-job statuses aren't in the shared order-status enum, so give them
  // readable labels locally, falling back to the order-status labels where they
  // overlap.
  function jobStatusLabel(status: string): string {
    const local: Record<string, string> = {
      accepted: 'Accepted',
      pickup: 'At pickup',
      picked_up: 'Picked up',
      in_transit: 'In transit',
      delivered: 'Delivered',
      failed: 'Failed',
      cancelled: 'Cancelled',
      unassigned: 'Unassigned',
    };
    return local[status] ?? orderStatusLabel(status);
  }

}

function CourierStat({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="card">
      <div className="card-body">
        <div className="subtle">{label}</div>
        <div style={{ fontSize: 22, fontWeight: 700, marginTop: 4 }}>{value}</div>
      </div>
    </div>
  );
}

const VEHICLES: { value: string; label: string; icon: string }[] = [
  { value: 'bicycle', label: 'Bicycle', icon: '🚲' },
  { value: 'motorcycle', label: 'Motorcycle', icon: '🏍' },
  { value: 'car', label: 'Car', icon: '🚗' },
];

function vehicleIcon(type: string | null | undefined): string {
  return VEHICLES.find((v) => v.value === type)?.icon ?? '🛵';
}

function ProfileSection() {
  const profile = useRiderProfile();
  const update = useUpdateRiderProfile();
  const [vehicle, setVehicle] = useState<string>('');
  const [phone, setPhone] = useState<string>('');
  const [loaded, setLoaded] = useState(false);

  // Seed the form once from the loaded profile.
  if (!loaded && profile.data) {
    setVehicle(profile.data.vehicle_type ?? '');
    setPhone(profile.data.phone ?? '');
    setLoaded(true);
  }

  const save = (e: React.FormEvent) => {
    e.preventDefault();
    if (!vehicle || !phone.trim()) return;
    update.mutate({ vehicle_type: vehicle, phone: phone.trim() });
  };

  return (
    <div className="card">
      <div className="card-body stack">
        <h3 style={{ margin: 0 }}>Your profile</h3>
        <p className="subtle" style={{ marginTop: 0 }}>Keep your vehicle and contact details up to date.</p>

        <form onSubmit={save} className="stack" style={{ gap: 12 }}>
          <div className="field">
            <label>Vehicle type</label>
            <div className="vehicle-picker">
              {VEHICLES.map((v) => (
                <button
                  type="button"
                  key={v.value}
                  className={`vehicle-option ${vehicle === v.value ? 'selected' : ''}`}
                  onClick={() => setVehicle(v.value)}
                >
                  <span className="vehicle-option-icon" aria-hidden="true">{v.icon}</span>
                  {v.label}
                </button>
              ))}
            </div>
          </div>

          <div className="field">
            <label htmlFor="cp-phone">Contact number</label>
            <input
              id="cp-phone"
              className="input"
              type="tel"
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
              placeholder="+966 5X XXX XXXX"
            />
          </div>

          {update.isSuccess && <div className="alert alert-success">Profile updated.</div>}
          {update.isError && <div className="alert alert-error">Could not save. Check your details and try again.</div>}

          <div className="row-between" style={{ justifyContent: 'flex-end' }}>
            <button type="submit" className="btn btn-primary" disabled={update.isPending || !vehicle || !phone.trim()}>
              {update.isPending ? 'Saving…' : 'Save profile'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
