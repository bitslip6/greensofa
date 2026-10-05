import assert from 'node:assert/strict';
import test from 'node:test';
import fc from 'fast-check';

import {
  advanceState,
  canonicalParse,
  canonicalSerialize,
  createInitialState,
  hashGameState,
  validateGameState,
} from '../../core/index.js';

const moveArbitrary = fc.constantFrom(-1, 0, 1);
const inputTicksArbitrary = fc.array(
  fc.record({
    'peer-0': fc.record({ move: moveArbitrary }),
    'peer-1': fc.record({ move: moveArbitrary }),
  }),
  { maxLength: 300 },
);

function runInputs(seed, ticks) {
  let state = createInitialState({ seed });
  for (const inputs of ticks) {
    state = advanceState(state, inputs);
  }
  return state;
}

test('property: identical seed and input stream produce byte-identical state and hash', async () => {
  await fc.assert(fc.asyncProperty(
    fc.integer({ min: 0, max: 0xffff_ffff }),
    inputTicksArbitrary,
    async (seed, ticks) => {
      const first = runInputs(seed, ticks);
      const second = runInputs(seed, structuredClone(ticks));

      assert.equal(canonicalSerialize(first), canonicalSerialize(second));
      assert.equal(await hashGameState(first), await hashGameState(second));
    },
  ), { numRuns: 100 });
});

test('property: state invariants and bounds hold over generated input streams', () => {
  fc.assert(fc.property(
    inputTicksArbitrary,
    fc.integer({ min: 1, max: 1000 }),
    (ticks, bound) => {
      let state = createInitialState();
      for (const inputs of ticks) {
        const previous = state;
        state = advanceState(state, inputs, {
          positionMin: -bound,
          positionMax: bound,
        });
        assert.equal(state.tick, previous.tick + 1);
        assert.equal(state.worldAge, previous.worldAge + 1);
        assert.equal(validateGameState(state), true);
      }

      for (const player of Object.values(state.players)) {
        assert.ok(player.position >= -bound && player.position <= bound);
      }
    },
  ), { numRuns: 200 });
});

const canonicalValueArbitrary = fc.letrec((tie) => ({
  value: fc.oneof(
    { depthSize: 'small' },
    fc.constant(null),
    fc.boolean(),
    fc.string(),
    fc.integer({ min: Number.MIN_SAFE_INTEGER, max: Number.MAX_SAFE_INTEGER }),
    fc.array(tie('value'), { maxLength: 5 }),
    fc.dictionary(
      fc.string().filter((key) => key !== '__proto__'),
      tie('value'),
      { maxKeys: 5 },
    ),
  ),
})).value;

test('property: canonical serialize/parse round trips supported values', () => {
  fc.assert(fc.property(canonicalValueArbitrary, (value) => {
    const serialized = canonicalSerialize(value);
    assert.equal(canonicalSerialize(canonicalParse(serialized)), serialized);
  }), { numRuns: 500 });
});

test('property: canonical state output is independent of player insertion order', () => {
  fc.assert(fc.property(
    fc.integer({ min: -1000, max: 1000 }),
    fc.integer({ min: -1000, max: 1000 }),
    (firstPosition, secondPosition) => {
      const state = createInitialState();
      state.players['peer-0'].position = firstPosition;
      state.players['peer-1'].position = secondPosition;

      const reversed = {
        seed: state.seed,
        players: {
          'peer-1': { position: secondPosition, slot: 1 },
          'peer-0': { position: firstPosition, slot: 0 },
        },
        worldAge: state.worldAge,
        schemaVersion: state.schemaVersion,
        tick: state.tick,
      };

      assert.equal(canonicalSerialize(state), canonicalSerialize(reversed));
    },
  ));
});
