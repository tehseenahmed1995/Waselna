import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// The dev server runs inside a container bound to 0.0.0.0, but the browser
// reaches it at localhost:5173. HMR is pinned to that client-facing host/port so
// the WebSocket connects reliably; without it the client falls back to periodic
// full-page reloads, which looks like the page refreshing on its own. File
// watching uses polling because change events do not propagate reliably across a
// Windows bind mount.
export default defineConfig({
  plugins: [react()],
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    hmr: {
      host: 'localhost',
      clientPort: 5173,
      protocol: 'ws',
    },
    watch: {
      usePolling: true,
      interval: 300,
    },
  },
});
