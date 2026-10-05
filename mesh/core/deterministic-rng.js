import { fail } from './errors.js';

const UINT32_RANGE = 0x1_0000_0000;
const STEP = 0x6d2b79f5;

function validateUint32(value, label) {
  if (!Number.isInteger(value) || value < 0 || value > 0xffff_ffff) {
    fail('INVALID_RNG_ARGUMENT', `${label} must be an unsigned 32-bit integer.`);
  }
}

/**
 * A small, explicitly seeded Mulberry32 generator.
 *
 * Its integer operations are part of the deterministic simulation contract.
 * It must not be replaced without a state schema/protocol version change.
 */
export class DeterministicRng {
  #state;

  constructor(seed) {
    validateUint32(seed, 'seed');
    this.#state = seed >>> 0;
  }

  get state() {
    return this.#state;
  }

  clone() {
    return new DeterministicRng(this.#state);
  }

  nextUint32() {
    this.#state = (this.#state + STEP) >>> 0;

    let value = this.#state;
    value = Math.imul(value ^ (value >>> 15), value | 1);
    value ^= value + Math.imul(value ^ (value >>> 7), value | 61);
    return (value ^ (value >>> 14)) >>> 0;
  }

  nextFloat() {
    return this.nextUint32() / UINT32_RANGE;
  }
}

export function createDeterministicRng(seed) {
  return new DeterministicRng(seed);
}
