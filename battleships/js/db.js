// ─────────────────────────────────────────────────────────────────────────────
// db.js  –  All database operations via WebSocket
//
// wsRequest() is defined in realtime.js (loaded after this file), but called
// only inside async function bodies so it is always available at call time.
// ─────────────────────────────────────────────────────────────────────────────

// ── Player identity (persisted in sessionStorage) ─────────────────────────────

function getOrCreatePlayerId() {
  let id = sessionStorage.getItem('bs_player_id');
  if (!id) {
    id = crypto.randomUUID();
    sessionStorage.setItem('bs_player_id', id);
  }
  return id;
}

function getPlayerName() {
  return localStorage.getItem('bs_player_name') || '';
}

function setPlayerName(name) {
  localStorage.setItem('bs_player_name', name);
}

// ── Room operations ───────────────────────────────────────────────────────────

async function dbCreateRoom(rules) {
  return wsRequest('create_room', { rules });
}

// Creates a room AND claims the P1 slot in one shot (for Play on this Device mode).
async function dbCreateAndJoinRoom(rules) {
  const playerId = getOrCreatePlayerId();
  const data = await wsRequest('create_room', {
    rules,
    player1_id:   playerId,
    player1_name: getPlayerName(),
  });
  return { ...data, _playerNum: 1, _rejoin: false };
}

async function dbJoinRoom(roomCode) {
  const playerId = getOrCreatePlayerId();
  // Server returns { ...room, _playerNum, _rejoin } or throws
  return wsRequest('join_room', {
    room_code:   roomCode,
    player_id:   playerId,
    player_name: getPlayerName(),
  });
}

async function dbGetRoom(roomId) {
  return wsRequest('get_room', { room_id: roomId });
}

async function dbUpdateRoomStatus(roomId, status, winnerId = null, winnerName = null) {
  await wsRequest('update_room_status', {
    room_id:     roomId,
    status,
    winner_id:   winnerId,
    winner_name: winnerName,
  });
}

async function dbStartBattle(roomId, firstPlayerId) {
  await wsRequest('start_battle', { room_id: roomId, first_player_id: firstPlayerId });
}

async function dbUpdateTurn(roomId, nextPlayerId) {
  await wsRequest('update_turn', { room_id: roomId, next_player_id: nextPlayerId });
}

// ── Board operations ──────────────────────────────────────────────────────────

async function dbSaveBoard(roomId, playerId, board, ships) {
  await wsRequest('save_board', { room_id: roomId, player_id: playerId, board, ships });
}

async function dbGetBoards(roomId) {
  return wsRequest('get_boards', { room_id: roomId });
}

// ── Move log ──────────────────────────────────────────────────────────────────

async function dbGetMoves(roomId) {
  return wsRequest('get_moves', { room_id: roomId });
}

async function dbRecordMove(roomId, playerId, row, col, hit, shipSunk) {
  await wsRequest('record_move', {
    room_id:   roomId,
    player_id: playerId,
    row, col, hit,
    ship_sunk: shipSunk || null,
  });
}

async function dbGetLeaderboard() {
  return wsRequest('get_leaderboard', {});
}
