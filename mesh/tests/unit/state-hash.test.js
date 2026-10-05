import assert from 'node:assert/strict';
import test from 'node:test';

import {
  MeshStateError,
  canonicalBytes,
  canonicalParse,
  canonicalSerialize,
  createInitialState,
  hashCanonicalValue,
  hashGameState,
} from '../../core/index.js';

test('canonical serialization recursively ignores object insertion order', () => {
  const first = {
    z: [{ second: 2, first: 1 }],
    a: { y: true, x: null },
  };
  const second = {
    a: { x: null, y: true },
    z: [{ first: 1, second: 2 }],
  };

  const expected = '{"a":{"x":null,"y":true},"z":[{"first":1,"second":2}]}';
  assert.equal(canonicalSerialize(first), expected);
  assert.equal(canonicalSerialize(second), expected);
  assert.deepEqual(canonicalBytes(first), canonicalBytes(second));
});

test('canonical parse accepts canonical text and rejects merely valid JSON', () => {
  const serialized = '{"a":1,"b":[false,null,"text"]}';
  assert.deepEqual(canonicalParse(serialized), { a: 1, b: [false, null, 'text'] });
  assert.throws(
    () => canonicalParse('{"b":2,"a":1}'),
    (error) => error instanceof MeshStateError && error.code === 'NON_CANONICAL_JSON',
  );
});

test('canonical serialization rejects unsupported or lossy values', () => {
  const sparse = [];
  sparse[1] = 1;
  const cyclic = {};
  cyclic.self = cyclic;

  for (const value of [
    undefined,
    () => {},
    Number.NaN,
    Number.POSITIVE_INFINITY,
    1.5,
    1n,
    { missing: undefined },
    sparse,
    cyclic,
    new Date(0),
  ]) {
    assert.throws(() => canonicalSerialize(value), MeshStateError);
  }
});

test('SHA-256 is lower-case hexadecimal and matches a known vector', async () => {
  assert.equal(
    await hashCanonicalValue({ b: 2, a: 1 }),
    '43258cff783fe7036d8a43033f830adfc60ec037382473548ac742b888292777',
  );
});

test('state hashes include all simulation-relevant version 1 fields', async () => {
  const state = createInitialState();
  const baseHash = await hashGameState(state);
  assert.match(baseHash, /^[a-f0-9]{64}$/);

  const changedStates = [
    { ...state, tick: 1 },
    { ...state, worldAge: 1 },
    { ...state, seed: state.seed + 1 },
    {
      ...state,
      players: {
        ...state.players,
        'peer-0': { ...state.players['peer-0'], position: 1 },
      },
    },
  ];

  for (const changed of changedStates) {
    assert.notEqual(await hashGameState(changed), baseHash);
  }
});
