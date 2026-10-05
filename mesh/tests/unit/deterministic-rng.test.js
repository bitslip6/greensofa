import assert from 'node:assert/strict';
import test from 'node:test';

import {
  MeshStateError,
  createDeterministicRng,
} from '../../core/index.js';

test('Mulberry32 repeats the same sequence for the same seed', () => {
  const first = createDeterministicRng(123456);
  const second = createDeterministicRng(123456);

  const firstValues = Array.from({ length: 20 }, () => first.nextUint32());
  const secondValues = Array.from({ length: 20 }, () => second.nextUint32());
  assert.deepEqual(firstValues, secondValues);
});

test('Mulberry32 has a stable documented integer sequence', () => {
  const rng = createDeterministicRng(1);
  assert.deepEqual(
    Array.from({ length: 5 }, () => rng.nextUint32()),
    [2693262067, 11749833, 2265367787, 4213581821, 4159151403],
  );
});

test('cloning preserves generator state without coupling instances', () => {
  const original = createDeterministicRng(99);
  original.nextUint32();
  const clone = original.clone();

  assert.equal(original.nextUint32(), clone.nextUint32());
  clone.nextUint32();
  assert.notEqual(original.state, clone.state);
});

test('RNG seeds must be unsigned 32-bit integers', () => {
  for (const seed of [-1, 0x1_0000_0000, 1.5, Number.NaN]) {
    assert.throws(
      () => createDeterministicRng(seed),
      (error) => error instanceof MeshStateError && error.code === 'INVALID_RNG_ARGUMENT',
    );
  }
});
