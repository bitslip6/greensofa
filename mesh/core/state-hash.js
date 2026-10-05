import { fail } from './errors.js';
import { validateGameState } from './game-state.js';

function canonicalize(value, ancestors) {
  if (value === null || typeof value === 'boolean' || typeof value === 'string') {
    return value;
  }

  if (typeof value === 'number') {
    if (!Number.isSafeInteger(value)) {
      fail('UNSUPPORTED_CANONICAL_VALUE', 'Canonical numbers must be safe integers.');
    }
    return Object.is(value, -0) ? 0 : value;
  }

  if (typeof value !== 'object') {
    fail('UNSUPPORTED_CANONICAL_VALUE', `Canonical serialization does not support ${typeof value}.`);
  }

  if (ancestors.has(value)) {
    fail('CYCLIC_CANONICAL_VALUE', 'Canonical serialization does not support cyclic values.');
  }
  ancestors.add(value);

  let result;
  if (Array.isArray(value)) {
    const ownKeys = Reflect.ownKeys(value);
    const expectedKeyCount = value.length + (Object.hasOwn(value, 'length') ? 1 : 0);
    if (ownKeys.length !== expectedKeyCount) {
      fail('UNSUPPORTED_CANONICAL_VALUE', 'Canonical arrays cannot be sparse or have extra properties.');
    }

    result = [];
    for (let index = 0; index < value.length; index += 1) {
      if (!Object.hasOwn(value, index)) {
        fail('UNSUPPORTED_CANONICAL_VALUE', 'Canonical arrays cannot be sparse.');
      }
      const descriptor = Object.getOwnPropertyDescriptor(value, String(index));
      if (!descriptor.enumerable || !Object.hasOwn(descriptor, 'value')) {
        fail('UNSUPPORTED_CANONICAL_VALUE', 'Canonical arrays require enumerable data elements.');
      }
      result.push(canonicalize(descriptor.value, ancestors));
    }
  } else {
    const prototype = Object.getPrototypeOf(value);
    if (prototype !== Object.prototype && prototype !== null) {
      fail('UNSUPPORTED_CANONICAL_VALUE', 'Canonical objects must be plain objects.');
    }
    if (Object.getOwnPropertySymbols(value).length > 0
      || Object.getOwnPropertyNames(value).length !== Object.keys(value).length) {
      fail('UNSUPPORTED_CANONICAL_VALUE', 'Canonical objects require enumerable string keys.');
    }

    result = Object.create(null);
    for (const key of Object.keys(value).sort()) {
      const descriptor = Object.getOwnPropertyDescriptor(value, key);
      if (!descriptor.enumerable || !Object.hasOwn(descriptor, 'value')) {
        fail('UNSUPPORTED_CANONICAL_VALUE', 'Canonical objects require enumerable data properties.');
      }
      result[key] = canonicalize(descriptor.value, ancestors);
    }
  }

  ancestors.delete(value);
  return result;
}

export function canonicalSerialize(value) {
  return JSON.stringify(canonicalize(value, new Set()));
}

export function canonicalParse(serialized) {
  if (typeof serialized !== 'string') {
    fail('INVALID_CANONICAL_JSON', 'Canonical JSON must be a string.');
  }

  let value;
  try {
    value = JSON.parse(serialized);
  } catch (error) {
    fail('INVALID_CANONICAL_JSON', `Invalid canonical JSON: ${error.message}`);
  }

  if (canonicalSerialize(value) !== serialized) {
    fail('NON_CANONICAL_JSON', 'JSON text is valid but is not canonically serialized.');
  }
  return value;
}

export function canonicalBytes(value) {
  return new TextEncoder().encode(canonicalSerialize(value));
}

export async function hashCanonicalValue(value) {
  const subtle = globalThis.crypto?.subtle;
  if (subtle === undefined) {
    fail('CRYPTO_UNAVAILABLE', 'Web Crypto SHA-256 support is required.');
  }

  const digest = await subtle.digest('SHA-256', canonicalBytes(value));
  return [...new Uint8Array(digest)]
    .map((byte) => byte.toString(16).padStart(2, '0'))
    .join('');
}

export async function hashGameState(state) {
  validateGameState(state);
  return hashCanonicalValue(state);
}
