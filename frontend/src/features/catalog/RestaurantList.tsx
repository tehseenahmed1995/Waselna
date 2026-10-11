import { useState } from 'react';
import { useRestaurant, useRestaurants } from './useRestaurants';
import { useAddToCart, money } from '../cart/useCart';

export function RestaurantList() {
  const [selected, setSelected] = useState<string | null>(null);
  const restaurants = useRestaurants();
  const detail = useRestaurant(selected);
  const addToCart = useAddToCart();
  const [addedId, setAddedId] = useState<string | null>(null);

  const add = (menuItemId: string) => {
    addToCart.mutate(
      { menu_item_id: menuItemId, quantity: 1 },
      {
        onSuccess: () => {
          setAddedId(menuItemId);
          setTimeout(() => setAddedId(null), 1200);
        },
      },
    );
  };

  if (restaurants.isLoading) {
    return (
      <div className="empty">
        <span className="spinner" /> Loading restaurants…
      </div>
    );
  }

  if (selected && detail.data) {
    const r = detail.data;
    const currency = r.currency;
    return (
      <div className="stack">
        <button className="btn btn-ghost btn-sm" style={{ alignSelf: 'flex-start' }} onClick={() => setSelected(null)}>
          ← All restaurants
        </button>
        <div className="card">
          <div className="card-body">
            <div className="row-between">
              <div>
                <h2 style={{ marginBottom: 2 }}>{r.name}</h2>
                <p className="subtle">{r.description}</p>
              </div>
              {r.rating !== null && <span className="badge badge-active">★ {r.rating}</span>}
            </div>
            <div className="mt-2">
              {r.cuisine_tags.map((t) => (
                <span className="tag" key={t}>
                  {t}
                </span>
              ))}
            </div>
          </div>
        </div>

        {r.menu.map((cat) => (
          <div className="card" key={cat.id}>
            <div className="card-body" style={{ paddingBottom: 4 }}>
              <h3>{cat.name}</h3>
            </div>
            {cat.items.map((item) => (
              <div className="list-row" key={item.id}>
                <div className="row-main">
                  <strong>{item.name}</strong>
                  <span className="subtle">{item.description}</span>
                  <span className="price mt-2">
                    {money({ amount: item.price.minor_units, currency })}
                  </span>
                </div>
                <button
                  className="btn btn-primary btn-sm"
                  disabled={!item.is_available || addToCart.isPending}
                  onClick={() => add(item.id)}
                >
                  {addedId === item.id ? 'Added ✓' : item.is_available ? 'Add' : 'Unavailable'}
                </button>
              </div>
            ))}
          </div>
        ))}
      </div>
    );
  }

  return (
    <div className="stack">
      <h2>Restaurants near you</h2>
      {restaurants.data?.map((r) => (
        <button key={r.id} className="card" style={{ textAlign: 'left', cursor: 'pointer', border: 'none' }} onClick={() => setSelected(r.id)}>
          <div className="card-body">
            <div className="row-between">
              <strong style={{ fontSize: 16 }}>{r.name}</strong>
              {r.rating !== null && <span className="badge badge-active">★ {r.rating}</span>}
            </div>
            <p className="subtle mt-2" style={{ margin: 0 }}>
              {r.description}
            </p>
            <div className="mt-2">
              {r.cuisine_tags.map((t) => (
                <span className="tag" key={t}>
                  {t}
                </span>
              ))}
            </div>
          </div>
        </button>
      ))}
    </div>
  );
}
