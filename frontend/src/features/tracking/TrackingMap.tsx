import { useEffect, useRef, useState } from 'react';
import { MapContainer, TileLayer, Marker, Popup, Polyline, useMap } from 'react-leaflet';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import type { LatLng } from './useTracking';

// Coloured div-icons so pickup / dropoff / courier are distinguishable without
// shipping image assets (the default Leaflet marker images break under Vite).
function pin(color: string, emoji: string): L.DivIcon {
  return L.divIcon({
    className: 'map-pin',
    html: `<div class="map-pin-inner" style="background:${color}">${emoji}</div>`,
    iconSize: [30, 30],
    iconAnchor: [15, 15],
    popupAnchor: [0, -14],
  });
}

const PICKUP_ICON = pin('#e8512f', '🏪');
const DROPOFF_ICON = pin('#1a1d23', '📍');
const COURIER_ICON = pin('#15803d', '🛵');

type Point = [number, number];

function FitBounds({ points }: { points: LatLng[] }) {
  const map = useMap();
  useEffect(() => {
    if (points.length === 0) return;
    if (points.length === 1) {
      map.setView([points[0].latitude, points[0].longitude], 15);
      return;
    }
    const bounds = L.latLngBounds(points.map((p) => [p.latitude, p.longitude] as Point));
    map.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 });
  }, [map, points]);
  return null;
}

export function TrackingMap({
  pickup,
  dropoff,
  courier,
}: {
  pickup: LatLng | null;
  dropoff: LatLng | null;
  courier: LatLng | null;
}) {
  const points = [pickup, dropoff, courier].filter((p): p is LatLng => p !== null);
  const center: Point = points.length > 0
    ? [points[0].latitude, points[0].longitude]
    : [24.7136, 46.6753];

  // Accumulate the courier's actual path so a growing trail is drawn as new
  // positions arrive in real time. De-dupe consecutive identical points.
  const [trail, setTrail] = useState<Point[]>([]);
  const lastKey = useRef<string | null>(null);
  useEffect(() => {
    if (!courier) return;
    const key = `${courier.latitude},${courier.longitude}`;
    if (key === lastKey.current) return;
    lastKey.current = key;
    setTrail((prev) => [...prev, [courier.latitude, courier.longitude]]);
  }, [courier]);

  const planned: Point[] | null = pickup && dropoff
    ? [[pickup.latitude, pickup.longitude], [dropoff.latitude, dropoff.longitude]]
    : null;

  return (
    <div className="tracking-map">
      <MapContainer center={center} zoom={14} scrollWheelZoom={false} style={{ height: '100%', width: '100%' }}>
        <TileLayer
          attribution='&copy; OpenStreetMap contributors'
          url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
        />

        {/* Planned route: pickup → dropoff (dashed). */}
        {planned && <Polyline positions={planned} pathOptions={{ color: '#98a2b3', weight: 3, dashArray: '6 8' }} />}

        {/* Courier's actual travelled path (solid green), growing live. */}
        {trail.length > 1 && <Polyline positions={trail} pathOptions={{ color: '#15803d', weight: 4 }} />}

        {pickup && (
          <Marker position={[pickup.latitude, pickup.longitude]} icon={PICKUP_ICON}>
            <Popup>Pickup — restaurant</Popup>
          </Marker>
        )}
        {dropoff && (
          <Marker position={[dropoff.latitude, dropoff.longitude]} icon={DROPOFF_ICON}>
            <Popup>Delivery address</Popup>
          </Marker>
        )}
        {courier && (
          <Marker position={[courier.latitude, courier.longitude]} icon={COURIER_ICON}>
            <Popup>Courier</Popup>
          </Marker>
        )}
        <FitBounds points={points} />
      </MapContainer>
    </div>
  );
}