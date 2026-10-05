import { fail } from './errors.js';

export const STATE_SCHEMA_VERSION = 1;
export const TICK_RATE_HZ = 60;
export const TICK_DURATION_MS = 1000 / TICK_RATE_HZ;
export const DEFAULT_SEED = 123456;
export const MAX_PLAYERS = 4;

export const DEFAULT_ROSTER = Object.freeze([
  Object.freeze({ peerId: 'peer-0', slot: 0 }),
  Object.freeze({ peerId: 'peer-1', slot: 1 }),
]);

const STATE_KEYS = Object.freeze([
  'players',
  'schemaVersion',
  'seed',
  'tick',
  'worldAge',
]);
const PLAYER_KEYS = Object.freeze(['position', 'slot']);
const PEER_ID_PATTERN = /^[A-Za-z0-9._:-]{1,64}$/;

function comparePeerIds(left, right) {
  return left < right ? -1 : left > right ? 1 : 0;
}

function setOwn(object, key, value) {
  Object.defineProperty(object, key, {
    value,
    enumerable: true,
    configurable: true,
    writable: true,
  });
}

function isPlainObject(value) {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) {
    return false;
  }

  const prototype = Object.getPrototypeOf(value);
  return prototype === Object.prototype || prototype === null;
}

function hasExactKeys(value, expectedKeys) {
  const keys = Reflect.ownKeys(value);
  if (keys.length !== expectedKeys.length || keys.some((key) => typeof key !== 'string')) {
    return false;
  }

  keys.sort();
  return keys.every((key, index) => {
    const descriptor = Object.getOwnPropertyDescriptor(value, key);
    return key === expectedKeys[index]
      && descriptor.enumerable
      && Object.hasOwn(descriptor, 'value');
  });
}

function validateSafeInteger(value, label, { minimum = Number.MIN_SAFE_INTEGER } = {}) {
  if (!Number.isSafeInteger(value) || value < minimum) {
    fail('INVALID_STATE', `${label} must be a safe integer of at least ${minimum}.`);
  }
}

function normalizeRoster(roster) {
  if (!Array.isArray(roster) || roster.length < 1 || roster.length > MAX_PLAYERS) {
    fail('INVALID_ROSTER', `Roster must contain between 1 and ${MAX_PLAYERS} players.`);
  }

  const seenPeerIds = new Set();
  const seenSlots = new Set();
  const normalized = roster.map((entry) => {
    if (!isPlainObject(entry)
      || typeof entry.peerId !== 'string'
      || !PEER_ID_PATTERN.test(entry.peerId)
      || !Number.isSafeInteger(entry.slot)
      || entry.slot < 0) {
      fail('INVALID_ROSTER', 'Every roster entry needs a valid peerId and non-negative integer slot.');
    }

    if (seenPeerIds.has(entry.peerId) || seenSlots.has(entry.slot)) {
      fail('INVALID_ROSTER', 'Roster peer IDs and slots must be unique.');
    }

    seenPeerIds.add(entry.peerId);
    seenSlots.add(entry.slot);
    return { peerId: entry.peerId, slot: entry.slot };
  });

  normalized.sort((left, right) => left.slot - right.slot
    || comparePeerIds(left.peerId, right.peerId));

  if (normalized.some((entry, index) => entry.slot !== index)) {
    fail('INVALID_ROSTER', 'Roster slots must be contiguous and start at zero.');
  }

  return normalized;
}

export function createInitialState({
  roster = DEFAULT_ROSTER,
  seed = DEFAULT_SEED,
} = {}) {
  if (!Number.isInteger(seed) || seed < 0 || seed > 0xffff_ffff) {
    fail('INVALID_SEED', 'State seed must be an unsigned 32-bit integer.');
  }

  const players = {};
  for (const { peerId, slot } of normalizeRoster(roster)) {
    setOwn(players, peerId, { slot, position: 0 });
  }

  return {
    schemaVersion: STATE_SCHEMA_VERSION,
    tick: 0,
    worldAge: 0,
    seed,
    players,
  };
}

export function playersInSlotOrder(state) {
  validateGameState(state);
  return Object.entries(state.players)
    .map(([peerId, player]) => ({ peerId, ...player }))
    .sort((left, right) => left.slot - right.slot
      || comparePeerIds(left.peerId, right.peerId));
}

export function validateGameState(state) {
  if (!isPlainObject(state) || !hasExactKeys(state, STATE_KEYS)) {
    fail('INVALID_STATE', 'Schema version 1 state has an invalid object shape.');
  }

  if (state.schemaVersion !== STATE_SCHEMA_VERSION) {
    fail('UNSUPPORTED_STATE_SCHEMA', `Unsupported state schema version: ${state.schemaVersion}.`);
  }

  validateSafeInteger(state.tick, 'tick', { minimum: 0 });
  validateSafeInteger(state.worldAge, 'worldAge', { minimum: 0 });

  if (!Number.isInteger(state.seed) || state.seed < 0 || state.seed > 0xffff_ffff) {
    fail('INVALID_STATE', 'seed must be an unsigned 32-bit integer.');
  }

  if (!isPlainObject(state.players)) {
    fail('INVALID_STATE', 'players must be a plain object.');
  }

  const entries = Object.entries(state.players);
  const playerKeys = Reflect.ownKeys(state.players);
  if (entries.length < 1
    || entries.length > MAX_PLAYERS
    || playerKeys.length !== entries.length
    || playerKeys.some((key) => {
      if (typeof key !== 'string') {
        return true;
      }
      const descriptor = Object.getOwnPropertyDescriptor(state.players, key);
      return !descriptor.enumerable || !Object.hasOwn(descriptor, 'value');
    })) {
    fail('INVALID_STATE', `players must contain between 1 and ${MAX_PLAYERS} enumerable data fields.`);
  }

  const slots = new Set();
  for (const [peerId, player] of entries) {
    if (!PEER_ID_PATTERN.test(peerId)
      || !isPlainObject(player)
      || !hasExactKeys(player, PLAYER_KEYS)) {
      fail('INVALID_STATE', `Player ${peerId} has an invalid shape.`);
    }

    validateSafeInteger(player.slot, `${peerId}.slot`, { minimum: 0 });
    validateSafeInteger(player.position, `${peerId}.position`);

    if (slots.has(player.slot)) {
      fail('INVALID_STATE', 'Player slots must be unique.');
    }
    slots.add(player.slot);
  }

  const sortedSlots = [...slots].sort((left, right) => left - right);
  if (sortedSlots.some((slot, index) => slot !== index)) {
    fail('INVALID_STATE', 'Player slots must be contiguous and start at zero.');
  }

  return true;
}

export function cloneGameState(state) {
  validateGameState(state);

  const players = {};
  for (const { peerId, slot, position } of playersInSlotOrder(state)) {
    setOwn(players, peerId, { slot, position });
  }

  return {
    schemaVersion: state.schemaVersion,
    tick: state.tick,
    worldAge: state.worldAge,
    seed: state.seed,
    players,
  };
}

/** Schema migration boundary. Version 1 is currently the only supported version. */
export function migrateGameState(state) {
  if (!isPlainObject(state) || state.schemaVersion !== STATE_SCHEMA_VERSION) {
    fail('UNSUPPORTED_STATE_SCHEMA', `Unsupported state schema version: ${state?.schemaVersion}.`);
  }

  return cloneGameState(state);
}
