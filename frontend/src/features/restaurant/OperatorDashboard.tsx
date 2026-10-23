import { useEffect, useState } from 'react';
import { money } from '../cart/useCart';
import { useEnums } from '../../shared/enums';
import { useCurrentUser } from '../auth/useAuth';
import { ApiRequestError } from '../../api/client';
import {
  useRestaurantQueue,
  useAcceptOrder,
  useReadyOrder,
  useRejectOrder,
} from './useRestaurantOrders';
import {
  useRestaurantProfile,
  useUpdateRestaurantProfile,
  useSubmitForReview,
  useMenuCategories,
  useCreateCategory,
  useUpdateCategory,
  useMenuItems,
  useCreateItem,
  useUpdateItem,
  useDeleteItem,
  useSetItemAvailability,
  useStaff,
  useInviteStaff,
  useRemoveStaff,
  useOrderHistory,
  useOrderDetail,
  type RestaurantProfile,
  type MenuCategory,
  type MenuItem,
  type NewMenuItem,
  type StaffMember,
  type NewStaff,
  type OrderFilters,
} from './useRestaurant';

const KITCHEN_LABELS: Record<string, string> = {
  confirmed: 'New',
  ready_for_pickup: 'Ready',
};

type Section = 'overview' | 'menu' | 'history' | 'staff' | 'profile' | 'orders';

const NAV: { key: Section; label: string; icon: string }[] = [
  { key: 'overview', label: 'Overview', icon: '▣' },
  { key: 'menu', label: 'Menu', icon: '🍽' },
  { key: 'history', label: 'Order details', icon: '📊' },
  { key: 'staff', label: 'Staff', icon: '👥' },
  { key: 'profile', label: 'Profile', icon: '🏪' },
  { key: 'orders', label: 'Kitchen queue', icon: '🧾' },
];

export function OperatorDashboard({ role = 'restaurant_operator' }: { role?: string }) {
  const me = useCurrentUser();
  const restaurantId = me.data?.restaurant_id ?? null;
  const isStaff = role === 'restaurant_staff';

  // Staff may only work the kitchen queue (view orders + accept/reject/ready).
  // Menu editing, profile, go-live, and staff management are operator-only, so
  // those sections are hidden from staff entirely rather than shown and 403ing.
  const nav = isStaff ? NAV.filter((n) => n.key === 'orders') : NAV;
  const [section, setSection] = useState<Section>(isStaff ? 'orders' : 'overview');

  return (
    <div className="admin-shell">
      <nav className="admin-sidebar">
        {nav.map((n) => (
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
        {!isStaff && section === 'overview' && <OverviewSection restaurantId={restaurantId} onGo={setSection} />}
        {!isStaff && section === 'menu' && <MenuSection restaurantId={restaurantId} />}
        {!isStaff && section === 'history' && <OrderHistorySection />}
        {!isStaff && section === 'staff' && <StaffSection active={section === 'staff'} />}
        {!isStaff && section === 'profile' && <ProfileSection restaurantId={restaurantId} />}
        {section === 'orders' && <OrdersSection />}
      </div>
    </div>
  );
}

// ---- Overview ---------------------------------------------------------------
function OverviewSection({ restaurantId, onGo }: { restaurantId: string | null; onGo: (s: Section) => void }) {
  const profile = useRestaurantProfile(restaurantId);
  const categories = useMenuCategories(restaurantId);
  const submit = useSubmitForReview(restaurantId);
  const { restaurantStatusLabel } = useEnums();
  const p = profile.data;

  const isDraft = p?.status === 'draft';
  const isPending = p?.status === 'pending_review';
  const isLive = p?.status === 'published';

  return (
    <div className="stack">
      <h1>{p?.name ?? 'Your restaurant'}</h1>
      <div className="row-main" style={{ flexDirection: 'row', gap: 10, alignItems: 'center' }}>
        <span className={`badge ${isLive ? 'badge-active' : ''}`}>{p ? restaurantStatusLabel(p.status) : '…'}</span>
        <span className="subtle">{(categories.data?.length ?? 0)} menu categor(ies)</span>
      </div>

      {isDraft && (
        <div className="card">
          <div className="card-body stack">
            <h3 style={{ margin: 0 }}>Get ready to go live</h3>
            <p className="subtle">
              Your restaurant is a draft. Complete your profile and add a few menu items, then submit it for review.
              An administrator publishes it so customers can order.
            </p>
            <div className="row-between" style={{ gap: 8, justifyContent: 'flex-start' }}>
              <button className="btn btn-ghost btn-sm" onClick={() => onGo('profile')}>Edit profile</button>
              <button className="btn btn-ghost btn-sm" onClick={() => onGo('menu')}>Manage menu</button>
              <button
                className="btn btn-primary btn-sm"
                disabled={submit.isPending}
                onClick={() => submit.mutate()}
              >
                {submit.isPending ? 'Submitting…' : 'Submit for review'}
              </button>
            </div>
            {submit.error instanceof ApiRequestError && (
              <div className="alert alert-error">{submit.error.error.message}</div>
            )}
          </div>
        </div>
      )}

      {isPending && (
        <div className="alert alert-success">
          Submitted for review. An administrator will publish your restaurant shortly.
        </div>
      )}

      {isLive && (
        <div className="alert alert-success">Your restaurant is live. Head to the kitchen queue to take orders.</div>
      )}
    </div>
  );
}

// ---- Menu -------------------------------------------------------------------
function MenuSection({ restaurantId }: { restaurantId: string | null }) {
  const categories = useMenuCategories(restaurantId);
  const profile = useRestaurantProfile(restaurantId);
  const currency = profile.data?.currency ?? 'USD';
  const [activeCat, setActiveCat] = useState<string | null>(null);
  const [showCatModal, setShowCatModal] = useState(false);
  const [editCat, setEditCat] = useState<MenuCategory | null>(null);

  useEffect(() => {
    if (activeCat === null && (categories.data?.length ?? 0) > 0) {
      setActiveCat(categories.data![0].id);
    }
  }, [categories.data, activeCat]);

  return (
    <div className="stack">
      <div className="row-between">
        <h1>Menu</h1>
        <button className="btn btn-primary" onClick={() => setShowCatModal(true)}>+ Add category</button>
      </div>

      {(categories.data?.length ?? 0) === 0 && (
        <div className="empty">No categories yet. Add one to start building your menu.</div>
      )}

      {(categories.data?.length ?? 0) > 0 && (
        <div className="menu-tabs">
          {categories.data!.map((c) => (
            <button
              key={c.id}
              className={`chip ${activeCat === c.id ? 'chip-active' : ''}`}
              onClick={() => setActiveCat(c.id)}
            >
              {c.name}
            </button>
          ))}
        </div>
      )}

      {activeCat && restaurantId && (
        <ItemsForCategory
          restaurantId={restaurantId}
          currency={currency}
          category={categories.data!.find((c) => c.id === activeCat)!}
          onEditCategory={() => setEditCat(categories.data!.find((c) => c.id === activeCat)!)}
        />
      )}

      {showCatModal && (
        <CategoryModal
          restaurantId={restaurantId}
          nextOrder={categories.data?.length ?? 0}
          onClose={() => setShowCatModal(false)}
        />
      )}
      {editCat && (
        <CategoryModal
          restaurantId={restaurantId}
          existing={editCat}
          nextOrder={editCat.display_order}
          onClose={() => setEditCat(null)}
        />
      )}
    </div>
  );
}

function ItemsForCategory({
  restaurantId,
  currency,
  category,
  onEditCategory,
}: {
  restaurantId: string;
  currency: string;
  category: MenuCategory;
  onEditCategory: () => void;
}) {
  const items = useMenuItems(restaurantId, category.id);
  const del = useDeleteItem(restaurantId);
  const avail = useSetItemAvailability(restaurantId);
  const [showItem, setShowItem] = useState(false);
  const [editItem, setEditItem] = useState<MenuItem | null>(null);

  return (
    <div className="stack">
      <div className="row-between">
        <h3 style={{ margin: 0 }}>{category.name}</h3>
        <div style={{ display: 'flex', gap: 8 }}>
          <button className="btn btn-ghost btn-sm" onClick={onEditCategory}>Rename category</button>
          <button className="btn btn-primary btn-sm" onClick={() => setShowItem(true)}>+ Add item</button>
        </div>
      </div>

      <div className="card">
        {(items.data?.filter((i) => !i.archived).length ?? 0) === 0 && (
          <div className="empty">No items in this category yet.</div>
        )}
        {items.data?.filter((i) => !i.archived).map((it) => (
          <div className="list-row" key={it.id}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
              {it.image_url ? (
                <img
                  src={it.image_url}
                  alt=""
                  className="item-thumb"
                  onError={(e) => ((e.target as HTMLImageElement).style.visibility = 'hidden')}
                />
              ) : (
                <div className="item-thumb item-thumb-empty" aria-hidden="true">🍽</div>
              )}
              <div className="row-main">
                <strong>{it.name}</strong>
                <span className="subtle">{it.description || 'No description'}</span>
              </div>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <span className="price">{money({ amount: it.price_minor, currency })}</span>
              <button
                className={`badge ${it.is_available ? 'badge-active' : ''}`}
                style={{ cursor: 'pointer', border: 'none' }}
                disabled={avail.isPending}
                onClick={() => avail.mutate({ itemId: it.id, is_available: !it.is_available })}
                title="Toggle availability"
              >
                {it.is_available ? 'Available' : 'Hidden'}
              </button>
              <button className="btn btn-sm btn-ghost" onClick={() => setEditItem(it)}>Edit</button>
              <button className="btn btn-sm btn-danger" onClick={() => del.mutate(it.id)} disabled={del.isPending}>
                Delete
              </button>
            </div>
          </div>
        ))}
      </div>

      {showItem && (
        <ItemModal
          restaurantId={restaurantId}
          categoryId={category.id}
          nextOrder={items.data?.length ?? 0}
          onClose={() => setShowItem(false)}
        />
      )}
      {editItem && (
        <ItemModal
          restaurantId={restaurantId}
          categoryId={category.id}
          existing={editItem}
          nextOrder={editItem.display_order}
          onClose={() => setEditItem(null)}
        />
      )}
    </div>
  );
}

function CategoryModal({
  restaurantId,
  existing,
  nextOrder,
  onClose,
}: {
  restaurantId: string | null;
  existing?: MenuCategory;
  nextOrder: number;
  onClose: () => void;
}) {
  const create = useCreateCategory(restaurantId);
  const update = useUpdateCategory(restaurantId);
  const [name, setName] = useState(existing?.name ?? '');
  const busy = create.isPending || update.isPending;

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim()) return;
    if (existing) {
      update.mutate({ id: existing.id, name, display_order: existing.display_order }, { onSuccess: onClose });
    } else {
      create.mutate({ name, display_order: nextOrder }, { onSuccess: onClose });
    }
  };

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal-card" onClick={(e) => e.stopPropagation()}>
        <h3>{existing ? 'Rename category' : 'Add category'}</h3>
        <form onSubmit={submit} className="stack" style={{ gap: 10, marginTop: 10 }}>
          <div className="field">
            <label htmlFor="cat-name">Category name</label>
            <input id="cat-name" className="input" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Pizzas" />
          </div>
          <div className="row-between" style={{ justifyContent: 'flex-end', gap: 10 }}>
            <button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button>
            <button type="submit" className="btn btn-primary" disabled={busy || !name.trim()}>
              {busy ? 'Saving…' : 'Save'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function ItemModal({
  restaurantId,
  categoryId,
  existing,
  nextOrder,
  onClose,
}: {
  restaurantId: string | null;
  categoryId: string;
  existing?: MenuItem;
  nextOrder: number;
  onClose: () => void;
}) {
  const create = useCreateItem(restaurantId);
  const update = useUpdateItem(restaurantId);
  const [name, setName] = useState(existing?.name ?? '');
  const [description, setDescription] = useState(existing?.description ?? '');
  const [price, setPrice] = useState(existing ? (existing.price_minor / 100).toFixed(2) : '');
  const [imageUrl, setImageUrl] = useState(existing?.image_url ?? '');
  const busy = create.isPending || update.isPending;
  const err = (create.error ?? update.error) instanceof ApiRequestError ? (create.error ?? update.error) as ApiRequestError : null;

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    const priceMinor = Math.round(parseFloat(price || '0') * 100);
    if (!name.trim() || priceMinor <= 0) return;
    const payload: NewMenuItem = {
      category_id: categoryId,
      name,
      description: description || null,
      image_url: imageUrl.trim() || null,
      price_minor: priceMinor,
      display_order: nextOrder,
    };
    if (existing) {
      update.mutate({ id: existing.id, ...payload }, { onSuccess: onClose });
    } else {
      create.mutate(payload, { onSuccess: onClose });
    }
  };

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal-card" onClick={(e) => e.stopPropagation()}>
        <h3>{existing ? 'Edit item' : 'Add item'}</h3>
        <form onSubmit={submit} className="stack" style={{ gap: 10, marginTop: 10 }}>
          <div className="field">
            <label htmlFor="it-name">Name</label>
            <input id="it-name" className="input" value={name} onChange={(e) => setName(e.target.value)} placeholder="Margherita" />
          </div>
          <div className="field">
            <label htmlFor="it-desc">Description</label>
            <input id="it-desc" className="input" value={description ?? ''} onChange={(e) => setDescription(e.target.value)} placeholder="Tomato, mozzarella, basil" />
          </div>
          <div className="field">
            <label htmlFor="it-price">Price</label>
            <input id="it-price" className="input" type="number" min="0" step="0.01" value={price} onChange={(e) => setPrice(e.target.value)} placeholder="9.99" />
          </div>
          <div className="field">
            <label htmlFor="it-image">Image URL (optional)</label>
            <input id="it-image" className="input" value={imageUrl} onChange={(e) => setImageUrl(e.target.value)} placeholder="https://…/photo.jpg" />
            {imageUrl.trim() && (
              <img src={imageUrl} alt="" className="item-image-preview" onError={(e) => ((e.target as HTMLImageElement).style.display = 'none')} onLoad={(e) => ((e.target as HTMLImageElement).style.display = 'block')} />
            )}
          </div>
          {err && <div className="alert alert-error">{err.error.message}</div>}
          <div className="row-between" style={{ justifyContent: 'flex-end', gap: 10 }}>
            <button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button>
            <button type="submit" className="btn btn-primary" disabled={busy}>
              {busy ? 'Saving…' : 'Save'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ---- Profile ----------------------------------------------------------------
function ProfileSection({ restaurantId }: { restaurantId: string | null }) {
  const profile = useRestaurantProfile(restaurantId);
  const update = useUpdateRestaurantProfile(restaurantId);
  const { currencyOptions } = useEnums();
  const [form, setForm] = useState<RestaurantProfile | null>(null);

  useEffect(() => {
    if (profile.data && form === null) setForm(profile.data);
  }, [profile.data, form]);

  if (!form) return <div className="empty"><span className="spinner" /> Loading…</div>;

  const save = (e: React.FormEvent) => {
    e.preventDefault();
    update.mutate({
      name: form.name,
      description: form.description,
      currency: form.currency,
      tax_rate_bp: form.tax_rate_bp,
      base_delivery_fee: form.base_delivery_fee,
      time_zone: form.time_zone || 'UTC',
      cuisine_tags: form.cuisine_tags ?? [],
    });
  };

  const err = update.error instanceof ApiRequestError ? update.error : null;

  return (
    <div className="stack">
      <h1>Restaurant profile</h1>
      <form onSubmit={save} className="card">
        <div className="card-body stack" style={{ gap: 12 }}>
          <div className="field">
            <label htmlFor="pf-name">Name</label>
            <input id="pf-name" className="input" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          </div>
          <div className="field">
            <label htmlFor="pf-desc">Description</label>
            <input id="pf-desc" className="input" value={form.description ?? ''} onChange={(e) => setForm({ ...form, description: e.target.value })} />
          </div>
          <div className="field">
            <label htmlFor="pf-cuisine">Cuisine tags (comma separated)</label>
            <input
              id="pf-cuisine"
              className="input"
              value={(form.cuisine_tags ?? []).join(', ')}
              onChange={(e) => setForm({ ...form, cuisine_tags: e.target.value.split(',').map((s) => s.trim()).filter(Boolean) })}
              placeholder="Italian, Pizza"
            />
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
            <div className="field">
              <label htmlFor="pf-currency">Currency</label>
              <select id="pf-currency" className="input" value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value })}>
                {currencyOptions.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
              </select>
            </div>
            <div className="field">
              <label htmlFor="pf-tz">Time zone</label>
              <input id="pf-tz" className="input" value={form.time_zone} onChange={(e) => setForm({ ...form, time_zone: e.target.value })} placeholder="UTC" />
            </div>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
            <div className="field">
              <label htmlFor="pf-fee">Base delivery fee (minor units)</label>
              <input id="pf-fee" className="input" type="number" min={0} value={form.base_delivery_fee} onChange={(e) => setForm({ ...form, base_delivery_fee: Number(e.target.value) })} />
            </div>
            <div className="field">
              <label htmlFor="pf-tax">Tax rate (basis points, 100 = 1%)</label>
              <input id="pf-tax" className="input" type="number" min={0} max={3000} value={form.tax_rate_bp} onChange={(e) => setForm({ ...form, tax_rate_bp: Number(e.target.value) })} />
            </div>
          </div>
          {err && <div className="alert alert-error">{err.error.message}</div>}
          {update.isSuccess && <div className="alert alert-success">Profile saved.</div>}
          <div className="row-between" style={{ justifyContent: 'flex-end' }}>
            <button type="submit" className="btn btn-primary" disabled={update.isPending}>
              {update.isPending ? 'Saving…' : 'Save profile'}
            </button>
          </div>
        </div>
      </form>
    </div>
  );
}

// ---- Orders (kitchen queue) -------------------------------------------------
function OrdersSection() {
  const queue = useRestaurantQueue();
  const accept = useAcceptOrder();
  const ready = useReadyOrder();
  const reject = useRejectOrder();
  const { orderStatusLabel } = useEnums();
  const statusLabel = (s: string) => KITCHEN_LABELS[s] ?? orderStatusLabel(s);

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
                <span className="badge badge-active">{statusLabel(o.status)}</span>
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
                  <button className="btn btn-primary btn-sm" disabled={accept.isPending} onClick={() => accept.mutate({ orderId: o.id })}>
                    Accept
                  </button>
                  <button className="btn btn-ghost btn-sm" disabled={reject.isPending} onClick={() => reject.mutate({ orderId: o.id, reason: 'Kitchen unavailable' })}>
                    Reject
                  </button>
                </>
              )}
              {o.status === 'preparing' && (
                <button className="btn btn-primary btn-sm" disabled={ready.isPending} onClick={() => ready.mutate({ orderId: o.id })}>
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

// ---- Staff ------------------------------------------------------------------
function StaffSection({ active }: { active: boolean }) {
  const staff = useStaff(active);
  const invite = useInviteStaff();
  const remove = useRemoveStaff();
  const [showInvite, setShowInvite] = useState(false);
  const [confirmRemove, setConfirmRemove] = useState<StaffMember | null>(null);

  return (
    <div className="stack">
      <div className="row-between">
        <h1>Staff</h1>
        <button className="btn btn-primary" onClick={() => setShowInvite(true)}>+ Add staff</button>
      </div>
      <p className="subtle" style={{ marginTop: -8 }}>
        Staff can view the kitchen queue and toggle item availability. They sign in with the email and password you set here.
      </p>

      <div className="card">
        {(staff.data?.length ?? 0) === 0 && <div className="empty">No staff yet. Invite your first team member.</div>}
        {staff.data?.map((m) => (
          <div className="list-row" key={m.id}>
            <div className="row-main">
              <strong>{m.display_name}</strong>
              <span className="subtle">{m.email}</span>
            </div>
            <button className="btn btn-sm btn-danger" onClick={() => setConfirmRemove(m)} disabled={remove.isPending}>
              Remove
            </button>
          </div>
        ))}
      </div>

      {showInvite && (
        <StaffModal
          submitting={invite.isPending}
          error={invite.error instanceof ApiRequestError ? invite.error.error.message : null}
          onClose={() => setShowInvite(false)}
          onSubmit={(form) => invite.mutate(form, { onSuccess: () => setShowInvite(false) })}
        />
      )}

      {confirmRemove && (
        <div className="modal-backdrop" onClick={() => setConfirmRemove(null)}>
          <div className="modal-card" onClick={(e) => e.stopPropagation()}>
            <h3>Remove {confirmRemove.display_name}?</h3>
            <p className="subtle mt-2">This staff member will lose access to your restaurant immediately.</p>
            <div className="row-between mt-2" style={{ justifyContent: 'flex-end', gap: 10 }}>
              <button className="btn btn-ghost" onClick={() => setConfirmRemove(null)}>Cancel</button>
              <button
                className="btn btn-danger"
                disabled={remove.isPending}
                onClick={() => remove.mutate(confirmRemove.id, { onSuccess: () => setConfirmRemove(null) })}
              >
                {remove.isPending ? 'Removing…' : 'Remove'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function StaffModal({
  submitting,
  error,
  onClose,
  onSubmit,
}: {
  submitting: boolean;
  error: string | null;
  onClose: () => void;
  onSubmit: (form: NewStaff) => void;
}) {
  const [displayName, setDisplayName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!displayName.trim() || !email.trim() || password.length < 12) return;
    onSubmit({ display_name: displayName, email, password });
  };

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal-card" onClick={(e) => e.stopPropagation()}>
        <h3>Add staff member</h3>
        <form onSubmit={submit} className="stack" style={{ gap: 10, marginTop: 10 }}>
          <div className="field">
            <label htmlFor="st-name">Name</label>
            <input id="st-name" className="input" value={displayName} onChange={(e) => setDisplayName(e.target.value)} placeholder="Jane Cook" />
          </div>
          <div className="field">
            <label htmlFor="st-email">Email</label>
            <input id="st-email" className="input" type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="jane@example.com" />
          </div>
          <div className="field">
            <label htmlFor="st-pass">Initial password (min 12 chars)</label>
            <input id="st-pass" className="input" type="password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="At least 12 characters" />
          </div>
          {error && <div className="alert alert-error">{error}</div>}
          <div className="row-between" style={{ justifyContent: 'flex-end', gap: 10 }}>
            <button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button>
            <button type="submit" className="btn btn-primary" disabled={submitting || password.length < 12}>
              {submitting ? 'Adding…' : 'Add staff'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ---- Order history (business-owner view) ------------------------------------
function OrderHistorySection() {
  const { data } = useEnums();
  const { orderStatusLabel } = useEnums();
  const [filters, setFilters] = useState<OrderFilters>({});
  const [detailId, setDetailId] = useState<string | null>(null);
  const history = useOrderHistory(filters);

  const orders = history.data ?? [];

  // Derive totals client-side from the filtered set. Revenue excludes
  // rejected/cancelled/failed orders (not real sales).
  const dead = ['rejected', 'cancelled', 'failed', 'payment_failed'];
  const revenue = orders.filter((o) => !dead.includes(o.status)).reduce((sum, o) => sum + o.order_total.amount, 0);
  const delivered = orders.filter((o) => o.status === 'delivered').length;
  const currency = orders[0]?.order_total.currency ?? 'USD';

  const update = (patch: Partial<OrderFilters>) => setFilters((f) => ({ ...f, ...patch }));

  return (
    <div className="stack">
      <h1>Order details</h1>

      {/* Filters */}
      <div className="card">
        <div className="card-body" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 12 }}>
          <div className="field">
            <label htmlFor="of-status">Status</label>
            <select id="of-status" className="input" value={filters.status ?? ''} onChange={(e) => update({ status: e.target.value || undefined })}>
              <option value="">All statuses</option>
              {data.order_statuses.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
            </select>
          </div>
          <div className="field">
            <label htmlFor="of-from">From</label>
            <input id="of-from" className="input" type="date" value={filters.from ?? ''} onChange={(e) => update({ from: e.target.value || undefined })} />
          </div>
          <div className="field">
            <label htmlFor="of-to">To</label>
            <input id="of-to" className="input" type="date" value={filters.to ?? ''} onChange={(e) => update({ to: e.target.value || undefined })} />
          </div>
          <div className="field">
            <label htmlFor="of-search">Order number</label>
            <input id="of-search" className="input" value={filters.search ?? ''} onChange={(e) => update({ search: e.target.value || undefined })} placeholder="WAS-…" />
          </div>
        </div>
      </div>

      {/* Totals */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 16 }}>
        <Stat label="Orders" value={orders.length} />
        <Stat label="Delivered" value={delivered} />
        <Stat label="Revenue" value={money({ amount: revenue, currency })} />
      </div>

      {/* List */}
      <div className="card">
        {history.isLoading && <div className="empty"><span className="spinner" /> Loading…</div>}
        {!history.isLoading && orders.length === 0 && <div className="empty">No orders match these filters.</div>}
        {orders.map((o) => (
          <button key={o.id} className="list-row list-row-button" onClick={() => setDetailId(o.id)}>
            <div className="row-main">
              <strong>{o.order_number}</strong>
              <span className="subtle">{o.line_count} item(s) · {new Date(o.created_at).toLocaleString()}</span>
            </div>
            <div style={{ textAlign: 'right' }}>
              <div className="price">{money(o.order_total)}</div>
              <span className="badge">{orderStatusLabel(o.status)}</span>
            </div>
          </button>
        ))}
      </div>

      {detailId && <OrderDetailModal orderId={detailId} onClose={() => setDetailId(null)} />}
    </div>
  );
}

function OrderDetailModal({ orderId, onClose }: { orderId: string; onClose: () => void }) {
  const detail = useOrderDetail(orderId);
  const { orderStatusLabel } = useEnums();
  const o = detail.data;

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal-card modal-card-lg" onClick={(e) => e.stopPropagation()}>
        {!o && <div className="empty"><span className="spinner" /> Loading…</div>}
        {o && (
          <div className="stack">
            <div className="row-between">
              <h3 style={{ margin: 0 }}>Order {o.order_number}</h3>
              <span className="badge badge-active">{orderStatusLabel(o.status)}</span>
            </div>
            <div className="subtle" style={{ marginTop: -6 }}>{new Date(o.created_at).toLocaleString()}</div>

            <div>
              <h4 style={{ margin: '4px 0' }}>Items</h4>
              {o.lines.map((l) => (
                <div className="summary-row" key={l.id}>
                  <span>{l.quantity} × {l.snapshot?.name ?? 'Item'}</span>
                  <span>{money(l.subtotal)}</span>
                </div>
              ))}
              <div className="summary-row"><span>Delivery fee</span><span>{money(o.breakdown.delivery_fee)}</span></div>
              <div className="summary-row"><span>Service fee</span><span>{money(o.breakdown.service_fee)}</span></div>
              <div className="summary-row"><span>Tax</span><span>{money(o.breakdown.tax_amount)}</span></div>
              <div className="summary-row total"><span>Total</span><span>{money(o.breakdown.order_total)}</span></div>
            </div>

            {o.payment && (
              <div>
                <h4 style={{ margin: '4px 0' }}>Payment</h4>
                <div className="summary-row">
                  <span>{o.payment.status === 'none' ? 'Cash on delivery' : o.payment.status}</span>
                  <span>{money(o.payment.amount)}</span>
                </div>
              </div>
            )}

            {o.delivery_address && (
              <div>
                <h4 style={{ margin: '4px 0' }}>Delivery address</h4>
                <div className="subtle">
                  {[o.delivery_address.line1, o.delivery_address.city].map((v) => (typeof v === 'string' ? v : '')).filter(Boolean).join(', ') || '—'}
                </div>
              </div>
            )}

            {o.timeline && o.timeline.length > 0 && (
              <div>
                <h4 style={{ margin: '4px 0' }}>Timeline</h4>
                {o.timeline.map((t, i) => (
                  <div className="summary-row" key={i}>
                    <strong style={{ fontSize: 13 }}>{orderStatusLabel(t.status)}</strong>
                    <span className="subtle">{new Date(t.at).toLocaleString()}</span>
                  </div>
                ))}
              </div>
            )}

            <div className="row-between" style={{ justifyContent: 'flex-end' }}>
              <button className="btn btn-ghost" onClick={onClose}>Close</button>
            </div>
          </div>
        )}
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
