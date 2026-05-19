// ─────────────────────────────────────────────────────────────────────────────
// realtime.js  –  WebSocket connection + real-time event dispatch
//
// Owns the single persistent WS connection to the game server.
// Exposes wsRequest() for request/response calls (used by db.js),
// and rtSubscribe / rtBroadcast / rtUnsubscribe for real-time events.
// Events are dispatched through the mutable HANDLERS object.
// ─────────────────────────────────────────────────────────────────────────────

const HANDLERS = {};

let _ws           = null;
let _wsReady      = false;
let _wsQueue      = [];          // messages buffered before connection opens
const _pending    = new Map();   // reqId → { resolve, reject }
let _currentRoomId = null;

// ── Internal helpers ──────────────────────────────────────────────────────────

function _makeId() {
  return Math.random().toString(36).substring(2, 10);
}

function _wsSend(msg) {
  if (_wsReady && _ws && _ws.readyState === WebSocket.OPEN) {
    _ws.send(JSON.stringify(msg));
  } else {
    _wsQueue.push(msg);
    if (!_ws) _initWS();
  }
}

function _rejectAllPending(reason) {
  for (const { reject } of _pending.values()) {
    reject(new Error(reason));
  }
  _pending.clear();
}

function _initWS() {
  if (_ws) return;

  _ws = new WebSocket(WS_URL);

  _ws.onopen = () => {
    _wsReady = true;

    // Identify our session
    _ws.send(JSON.stringify({
      id:      _makeId(),
      type:    'identify',
      payload: { playerId: getOrCreatePlayerId() },
    }));

    // Re-subscribe if we were in a room (handles page-visible reconnects)
    if (_currentRoomId) {
      _ws.send(JSON.stringify({
        id:      _makeId(),
        type:    'subscribe',
        payload: { room_id: _currentRoomId },
      }));
    }

    // Flush buffered messages
    _wsQueue.forEach(m => _ws.send(JSON.stringify(m)));
    _wsQueue = [];
  };

  _ws.onmessage = (e) => {
    let msg;
    try { msg = JSON.parse(e.data); } catch { return; }

    const { id, type, data, error, payload } = msg;

    // Response to a pending wsRequest call
    if (id && _pending.has(id)) {
      const { resolve, reject } = _pending.get(id);
      _pending.delete(id);
      if (error) reject(new Error(error));
      else       resolve(data);
      return;
    }

    // Incoming broadcast / presence event
    switch (type) {
      case 'player_joined':  HANDLERS.onPlayerJoined  && HANDLERS.onPlayerJoined(payload);  break;
      case 'ships_ready':    HANDLERS.onShipsReady    && HANDLERS.onShipsReady(payload);    break;
      case 'move':           HANDLERS.onMove          && HANDLERS.onMove(payload);          break;
      case 'game_over':      HANDLERS.onGameOver      && HANDLERS.onGameOver(payload);      break;
      case 'presence_leave': HANDLERS.onPresenceLeave && HANDLERS.onPresenceLeave(payload); break;
    }
  };

  _ws.onclose = () => {
    _wsReady = false;
    _ws      = null;
    _rejectAllPending('WebSocket disconnected');
    // Reconnect after 2 s
    setTimeout(_initWS, 2000);
  };

  _ws.onerror = () => {
    // onclose fires immediately after, which handles cleanup + reconnect
  };
}

// ── Public API ────────────────────────────────────────────────────────────────

// Send a request and return a Promise that resolves with the server's response.
function wsRequest(type, payload = {}) {
  return new Promise((resolve, reject) => {
    const id = _makeId();
    _pending.set(id, { resolve, reject });

    // 10-second timeout
    const timer = setTimeout(() => {
      if (_pending.has(id)) {
        _pending.delete(id);
        reject(new Error(`Request timed out: ${type}`));
      }
    }, 10000);

    // Wrap resolve/reject to cancel the timer
    _pending.set(id, {
      resolve: (v) => { clearTimeout(timer); resolve(v); },
      reject:  (e) => { clearTimeout(timer); reject(e);  },
    });

    _wsSend({ id, type, payload });
    if (!_ws) _initWS();
  });
}

function rtSubscribe(roomId) {
  _currentRoomId = roomId;
  wsRequest('subscribe', { room_id: roomId }).catch(console.warn);
}

async function rtBroadcast(event, payload) {
  if (!_currentRoomId) return;
  try {
    await wsRequest('broadcast', { room_id: _currentRoomId, event, payload });
  } catch (e) {
    console.warn('rtBroadcast failed:', e);
  }
}

function rtUnsubscribe() {
  if (_currentRoomId) {
    wsRequest('unsubscribe', { room_id: _currentRoomId }).catch(() => {});
    _currentRoomId = null;
  }
}

// Establish the connection as soon as the script loads
_initWS();
