import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { getAccessToken } from './client';

// Reverb speaks the Pusher protocol, so laravel-echo uses the pusher-js client
// pointed at our Reverb server. Config comes from Vite env (set by compose).
const key = import.meta.env.VITE_REVERB_APP_KEY ?? 'waselna-local-key';
const host = import.meta.env.VITE_REVERB_HOST ?? 'localhost';
const port = Number(import.meta.env.VITE_REVERB_PORT ?? 8080);
const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'http';
const apiBase = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api/v1';

// pusher-js is a required global for laravel-echo's pusher broadcaster.
(window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;

let echo: Echo | null = null;

/**
 * Lazily create a single Echo instance pointed at Reverb. Private channels are
 * authorized against our bearer-token endpoint (the app uses opaque tokens, not
 * Laravel sessions), so a custom authorizer forwards the Authorization header to
 * POST /broadcasting/auth. Returns null if the client can't be constructed (so
 * callers fall back to polling).
 */
export function getEcho(): Echo | null {
  if (echo) return echo;
  try {
    echo = new Echo({
      broadcaster: 'pusher',
      key,
      wsHost: host,
      wsPort: port,
      wssPort: port,
      forceTLS: scheme === 'https',
      enabledTransports: ['ws', 'wss'],
      authorizer: (channel: { name: string }) => ({
        authorize: (socketId: string, callback: (error: boolean, data: unknown) => void) => {
          fetch(`${apiBase}/broadcasting/auth`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              Authorization: `Bearer ${getAccessToken() ?? ''}`,
            },
            body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
          })
            .then((res) => (res.ok ? res.json() : Promise.reject(new Error('auth failed'))))
            .then((data) => callback(false, data))
            .catch((err) => callback(true, err));
        },
      }),
    });
    return echo;
  } catch {
    return null;
  }
}