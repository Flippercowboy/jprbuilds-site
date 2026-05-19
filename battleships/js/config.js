// ─────────────────────────────────────────────────────────────────────────────
// config.js  –  WebSocket server URL
//
// Local dev:   ws://localhost:3001
// Production:  wss://jprbuilds.com/battleships/ws  (proxied by nginx)
// ─────────────────────────────────────────────────────────────────────────────

const WS_URL = (() => {
  const isLocal = location.hostname === 'localhost' || location.hostname === '127.0.0.1';
  if (isLocal) return 'ws://localhost:3001';
  const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
  return `${proto}//${location.host}/battleships/ws`;
})();
