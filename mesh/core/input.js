import { fail } from './errors.js';

export const VALID_MOVES = Object.freeze([-1, 0, 1]);
export const NEUTRAL_INPUT = Object.freeze({ move: 0 });

function isPlainObject(value) {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) {
    return false;
  }

  const prototype = Object.getPrototypeOf(value);
  return prototype === Object.prototype || prototype === null;
}

export function validateInput(input) {
  if (!isPlainObject(input)) {
    fail('INVALID_INPUT', 'Input must be a plain object.');
  }

  const keys = Reflect.ownKeys(input);
  const moveDescriptor = Object.getOwnPropertyDescriptor(input, 'move');
  if (keys.length !== 1
    || keys[0] !== 'move'
    || !moveDescriptor?.enumerable
    || !Object.hasOwn(moveDescriptor, 'value')) {
    fail('INVALID_INPUT', 'Input must contain exactly one enumerable move data field.');
  }

  if (!Number.isInteger(moveDescriptor.value) || !VALID_MOVES.includes(moveDescriptor.value)) {
    fail('INVALID_INPUT_MOVE', 'Input move must be one of -1, 0, or 1.');
  }

  return true;
}

export function normalizeInput(input = NEUTRAL_INPUT) {
  validateInput(input);
  return Object.freeze({ move: input.move });
}
