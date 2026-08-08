// ─────────────────────────────────────────────────────────────────────────────
// server.js  –  Battleships WebSocket server
//
// Replaces Supabase entirely. Keeps all game state in memory.
// Rooms are cleaned up automatically after 24 hours.
//
// Deploy:
//   cd /path/to/battleships/server
//   npm install
//   npm start          (or: pm2 start server.js --name battleships)
//
// Nginx proxies  /battleships/ws  →  localhost:3001
// ─────────────────────────────────────────────────────────────────────────────

'use strict';

const { WebSocketServer } = require('ws');
const { randomUUID }      = require('crypto');
const http                = require('http');

const PORT = process.env.PORT || 3001;

// ── In-memory state ───────────────────────────────────────────────────────────

const rooms     = new Map(); // roomId → room object
const boards    = new Map(); // roomId → Map(playerId → boardData)
const moveLog   = new Map(); // roomId → Array of move objects
const sockets   = new Map(); // playerId → ws
const roomPeers = new Map(); // roomId → Set of playerIds

// ── HTTP + WebSocket server ───────────────────────────────────────────────────

const server = http.createServer((req, res) => {
  res.writeHead(200, { 'Content-Type': 'text/plain' });
  res.end('Battleships server OK\n');
});

const wss = new WebSocketServer({ server });

wss.on('connection', (ws) => {
  let myPlayerId = null;

  ws.on('message', (raw) => {
    let msg;
    try { msg = JSON.parse(raw); } catch { return; }

    const { id, type, payload = {} } = msg;

    // ── helpers ───────────────────────────────────────────────────────────────

    function reply(data, error) {
      if (ws.readyState !== 1) return;
      ws.send(JSON.stringify({ id, type, data: data ?? null, error: error ?? null }));
    }

    function broadcastToRoom(roomId, event, eventPayload, excludePlayerId) {
      const peers = roomPeers.get(roomId);
      if (!peers) return;
      for (const pid of peers) {
        if (pid === excludePlayerId) continue;
        const sock = sockets.get(pid);
        if (sock && sock.readyState === 1) {
          sock.send(JSON.stringify({ type: event, payload: eventPayload }));
        }
      }
    }

    // ── message handlers ──────────────────────────────────────────────────────

    switch (type) {

      // Player identifies their session
      case 'identify': {
        myPlayerId = payload.playerId;
        sockets.set(myPlayerId, ws);
        reply({ ok: true });
        break;
      }

      // Create a room (host view — no player claimed yet)
      case 'create_room': {
        const roomId   = randomUUID();
        const roomCode = Math.random().toString(36).substring(2, 8).toUpperCase();
        const room = {
          id:           roomId,
          room_code:    roomCode,
          status:       'waiting',
          rules:        payload.rules || 'classic',
          player1_id:   payload.player1_id   || null,
          player1_name: payload.player1_name || null,
          player2_id:   null,
          player2_name: null,
          current_turn: null,
          winner_id:    null,
          winner_name:  null,
          created_at:   new Date().toISOString(),
          updated_at:   new Date().toISOString(),
        };
        rooms.set(roomId, room);
        boards.set(roomId, new Map());
        moveLog.set(roomId, []);
        reply(room);
        break;
      }

      // Join a room by its 6-char code
      case 'join_room': {
        const code     = (payload.room_code || '').toUpperCase().trim();
        const playerId = payload.player_id;

        let found = null;
        for (const r of rooms.values()) {
          if (r.room_code === code) { found = r; break; }
        }

        if (!found) {
          reply(null, 'Room not found. Check the code and try again.');
          break;
        }

        // Rejoin detection — same player refreshing
        if (found.player1_id === playerId) { reply({ ...found, _playerNum: 1, _rejoin: true  }); break; }
        if (found.player2_id === playerId) { reply({ ...found, _playerNum: 2, _rejoin: true  }); break; }
        if (found.status === 'finished')   { reply(null, 'That game has already finished.');        break; }

        if (!found.player1_id) {
          found.player1_id   = playerId;
          found.player1_name = payload.player_name || null;
          found.updated_at   = new Date().toISOString();
          reply({ ...found, _playerNum: 1, _rejoin: false });
          break;
        }

        if (!found.player2_id) {
          found.player2_id   = playerId;
          found.player2_name = payload.player_name || null;
          found.status       = 'placing';
          found.updated_at   = new Date().toISOString();
          reply({ ...found, _playerNum: 2, _rejoin: false });
          break;
        }

        reply(null, 'Room is full. Both player slots are taken.');
        break;
      }

      case 'get_room': {
        const room = rooms.get(payload.room_id);
        if (!room) { reply(null, 'Room not found'); break; }
        reply(room);
        break;
      }

      case 'update_room_status': {
        const room = rooms.get(payload.room_id);
        if (!room) { reply(null, 'Room not found'); break; }
        room.status      = payload.status;
        room.updated_at  = new Date().toISOString();
        if (payload.winner_id)   room.winner_id   = payload.winner_id;
        if (payload.winner_name) room.winner_name = payload.winner_name;
        reply({ ok: true });
        break;
      }

      case 'start_battle': {
        const room = rooms.get(payload.room_id);
        if (!room) { reply(null, 'Room not found'); break; }
        room.status       = 'battle';
        room.current_turn = payload.first_player_id;
        room.updated_at   = new Date().toISOString();
        reply({ ok: true });
        break;
      }

      case 'update_turn': {
        const room = rooms.get(payload.room_id);
        if (!room) { reply(null, 'Room not found'); break; }
        room.current_turn = payload.next_player_id;
        room.updated_at   = new Date().toISOString();
        reply({ ok: true });
        break;
      }

      case 'save_board': {
        const roomBoards = boards.get(payload.room_id);
        if (!roomBoards) { reply(null, 'Room not found'); break; }
        roomBoards.set(payload.player_id, {
          room_id:      payload.room_id,
          player_id:    payload.player_id,
          board:        payload.board,
          ships:        payload.ships,
          ships_placed: true,
          updated_at:   new Date().toISOString(),
        });
        reply({ ok: true });
        break;
      }

      case 'get_boards': {
        const roomBoards = boards.get(payload.room_id);
        reply(roomBoards ? Array.from(roomBoards.values()) : []);
        break;
      }

      case 'record_move': {
        const log = moveLog.get(payload.room_id);
        if (!log) { reply(null, 'Room not found'); break; }
        log.push({
          id:         randomUUID(),
          room_id:    payload.room_id,
          player_id:  payload.player_id,
          row:        payload.row,
          col:        payload.col,
          hit:        payload.hit,
          ship_sunk:  payload.ship_sunk || null,
          created_at: new Date().toISOString(),
        });
        reply({ ok: true });
        break;
      }

      case 'get_moves': {
        const log = moveLog.get(payload.room_id);
        reply(log || []);
        break;
      }

      case 'get_leaderboard': {
        const results = [];
        for (const room of rooms.values()) {
          if (room.status === 'finished') {
            results.push({
              player1_name: room.player1_name,
              player2_name: room.player2_name,
              winner_name:  room.winner_name,
            });
          }
        }
        reply(results);
        break;
      }

      // Subscribe to real-time events for a room
      case 'subscribe': {
        const roomId = payload.room_id;
        if (!roomPeers.has(roomId)) roomPeers.set(roomId, new Set());
        roomPeers.get(roomId).add(myPlayerId);
        reply({ ok: true });
        break;
      }

      case 'unsubscribe': {
        const roomId = payload.room_id;
        if (roomPeers.has(roomId)) roomPeers.get(roomId).delete(myPlayerId);
        reply({ ok: true });
        break;
      }

      // Relay a broadcast event to all other peers in the room
      case 'broadcast': {
        broadcastToRoom(payload.room_id, payload.event, payload.payload, myPlayerId);
        reply({ ok: true });
        break;
      }

      // Presence tracking (just acknowledged; leave handled on socket close)
      case 'track_presence': {
        reply({ ok: true });
        break;
      }

      default:
        reply(null, `Unknown message type: ${type}`);
    }
  });

  ws.on('close', () => {
    if (!myPlayerId) return;
    sockets.delete(myPlayerId);

    // Notify all rooms this player was in that they left
    for (const [roomId, peers] of roomPeers.entries()) {
      if (!peers.has(myPlayerId)) continue;
      peers.delete(myPlayerId);
      for (const pid of peers) {
        const sock = sockets.get(pid);
        if (sock && sock.readyState === 1) {
          sock.send(JSON.stringify({
            type:    'presence_leave',
            payload: [{ playerId: myPlayerId }],
          }));
        }
      }
    }
  });

  ws.on('error', (err) => {
    console.error('WebSocket error:', err.message);
  });
});

// ── Hourly cleanup of rooms older than 24 hours ───────────────────────────────

setInterval(() => {
  const cutoff = Date.now() - 24 * 60 * 60 * 1000;
  let cleaned  = 0;
  for (const [id, room] of rooms.entries()) {
    if (new Date(room.created_at).getTime() < cutoff) {
      rooms.delete(id);
      boards.delete(id);
      moveLog.delete(id);
      roomPeers.delete(id);
      cleaned++;
    }
  }
  if (cleaned > 0) console.log(`[cleanup] Removed ${cleaned} old room(s)`);
}, 60 * 60 * 1000);

// ── Start ─────────────────────────────────────────────────────────────────────

server.listen(PORT, () => {
  console.log(`Battleships WS server listening on port ${PORT}`);
});
