import assert from 'node:assert/strict';
import test from 'node:test';

import {
  MeshStateError,
  advanceState,
  createInitialState,
  TICK_DURATION_MS,
  TICK_RATE_HZ,
  migrateGameState,
  playersInSlotOrder,
  validateInput,
} from '../../core/index.js';

function deepFreeze(value) {
  if (value !== null && typeof value === 'object' && !Object.isFrozen(value)) {
    Object.freeze(value);
    for (const child of Object.values(value)) {
      deepFreeze(child);
    }
  }
  return value;
}

test('initial state has the canonical schema version 1 defaults', () => {
  assert.deepEqual(createInitialState(), {
    schemaVersion: 1,
    tick: 0,
    worldAge: 0,
    seed: 123456,
    players: {
      'peer-0': { slot: 0, position: 0 },
      'peer-1': { slot: 1, position: 0 },
    },
  });
});

test('tick-rate metadata is 60 Hz without controlling advancement', () => {
  assert.equal(TICK_RATE_HZ, 60);
  assert.equal(TICK_DURATION_MS, 1000 / 60);
});

test('roster construction and processing use numeric slot order', () => {
  const state = createInitialState({
    roster: [
      { peerId: 'zebra', slot: 1 },
      { peerId: 'alpha', slot: 0 },
    ],
  });

  assert.deepEqual(
    playersInSlotOrder(state).map(({ peerId }) => peerId),
    ['alpha', 'zebra'],
  );
});

test('four-player state advances every owned input', () => {
  const state = createInitialState({
    roster: [3, 2, 1, 0].map((slot) => ({ peerId: `peer-${slot}`, slot })),
  });
  const next = advanceState(state, {
    'peer-0': { move: -1 },
    'peer-1': { move: 0 },
    'peer-2': { move: 1 },
    'peer-3': { move: 1 },
  });

  assert.deepEqual(
    Object.fromEntries(Object.entries(next.players).map(([peerId, player]) => [peerId, player.position])),
    { 'peer-0': -1, 'peer-1': 0, 'peer-2': 1, 'peer-3': 1 },
  );
});

test('one neutral tick advances tick and world age', () => {
  const next = advanceState(createInitialState());

  assert.equal(next.tick, 1);
  assert.equal(next.worldAge, 1);
  assert.equal(next.players['peer-0'].position, 0);
  assert.equal(next.players['peer-1'].position, 0);
});

test('movement applies per player and clamps to configured integer bounds', () => {
  let state = createInitialState();
  state = advanceState(state, {
    'peer-0': { move: -1 },
    'peer-1': { move: 1 },
  }, { positionMin: -1, positionMax: 1 });
  state = advanceState(state, {
    'peer-0': { move: -1 },
    'peer-1': { move: 1 },
  }, { positionMin: -1, positionMax: 1 });

  assert.equal(state.players['peer-0'].position, -1);
  assert.equal(state.players['peer-1'].position, 1);
});

test('advancement does not mutate historical state or input objects', () => {
  const previous = deepFreeze(createInitialState());
  const inputs = deepFreeze({
    'peer-0': { move: 1 },
    'peer-1': { move: -1 },
  });
  const previousCopy = structuredClone(previous);
  const inputsCopy = structuredClone(inputs);

  const next = advanceState(previous, inputs);

  assert.deepEqual(previous, previousCopy);
  assert.deepEqual(inputs, inputsCopy);
  assert.notStrictEqual(next, previous);
  assert.notStrictEqual(next.players, previous.players);
  assert.notStrictEqual(next.players['peer-0'], previous.players['peer-0']);
});

test('missing player input is neutral and unknown owners are rejected', () => {
  const next = advanceState(createInitialState(), { 'peer-0': { move: 1 } });
  assert.equal(next.players['peer-0'].position, 1);
  assert.equal(next.players['peer-1'].position, 0);

  assert.throws(
    () => advanceState(next, { stranger: { move: 0 } }),
    (error) => error instanceof MeshStateError && error.code === 'UNKNOWN_INPUT_OWNER',
  );
});

test('input accepts only an exact move of -1, 0, or 1', () => {
  for (const move of [-1, 0, 1]) {
    assert.equal(validateInput({ move }), true);
  }

  for (const input of [{}, { move: 2 }, { move: 0.5 }, { move: 0, extra: true }, null]) {
    assert.throws(() => validateInput(input), MeshStateError);
  }
});

test('version 1 migration boundary returns an independent canonical clone', () => {
  const state = createInitialState();
  const migrated = migrateGameState(state);

  assert.deepEqual(migrated, state);
  assert.notStrictEqual(migrated, state);
  assert.notStrictEqual(migrated.players, state.players);
  assert.throws(
    () => migrateGameState({ ...state, schemaVersion: 2 }),
    (error) => error.code === 'UNSUPPORTED_STATE_SCHEMA',
  );
});
