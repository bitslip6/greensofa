import { fail } from './errors.js';
import { NEUTRAL_INPUT, normalizeInput } from './input.js';
import { cloneGameState, playersInSlotOrder, validateGameState } from './game-state.js';

export const DEFAULT_POSITION_MIN = -1000;
export const DEFAULT_POSITION_MAX = 1000;

function normalizeContext(context) {
  const prototype = context === null ? undefined : Object.getPrototypeOf(context);
  if (context === null
    || typeof context !== 'object'
    || Array.isArray(context)
    || (prototype !== Object.prototype && prototype !== null)) {
    fail('INVALID_ADVANCE_CONTEXT', 'Advance context must be a plain object.');
  }

  const minDescriptor = Object.getOwnPropertyDescriptor(context, 'positionMin');
  const maxDescriptor = Object.getOwnPropertyDescriptor(context, 'positionMax');
  if ((minDescriptor && !Object.hasOwn(minDescriptor, 'value'))
    || (maxDescriptor && !Object.hasOwn(maxDescriptor, 'value'))) {
    fail('INVALID_ADVANCE_CONTEXT', 'Position bounds must be data fields.');
  }

  const positionMin = minDescriptor?.value ?? DEFAULT_POSITION_MIN;
  const positionMax = maxDescriptor?.value ?? DEFAULT_POSITION_MAX;

  if (!Number.isSafeInteger(positionMin)
    || !Number.isSafeInteger(positionMax)
    || positionMin > positionMax) {
    fail('INVALID_ADVANCE_CONTEXT', 'Position bounds must be safe integers with min <= max.');
  }

  return Object.freeze({ positionMin, positionMax });
}

function setOwn(object, key, value) {
  Object.defineProperty(object, key, {
    value,
    enumerable: true,
    configurable: true,
    writable: true,
  });
}

function normalizeInputFrame(state, inputsByPlayer) {
  const prototype = inputsByPlayer === null ? undefined : Object.getPrototypeOf(inputsByPlayer);
  if (inputsByPlayer === null
    || typeof inputsByPlayer !== 'object'
    || Array.isArray(inputsByPlayer)
    || (prototype !== Object.prototype && prototype !== null)) {
    fail('INVALID_INPUT_FRAME', 'Inputs by player must be a plain object.');
  }

  const knownPeerIds = new Set(Object.keys(state.players));
  for (const peerId of Reflect.ownKeys(inputsByPlayer)) {
    const descriptor = Object.getOwnPropertyDescriptor(inputsByPlayer, peerId);
    if (typeof peerId !== 'string'
      || !descriptor.enumerable
      || !Object.hasOwn(descriptor, 'value')) {
      fail('INVALID_INPUT_FRAME', 'Input frames require enumerable peer ID data fields.');
    }
    if (!knownPeerIds.has(peerId)) {
      fail('UNKNOWN_INPUT_OWNER', `Input owner ${peerId} is not in the state roster.`);
    }
  }

  const normalized = {};
  for (const { peerId } of playersInSlotOrder(state)) {
    setOwn(normalized, peerId, normalizeInput(
      Object.hasOwn(inputsByPlayer, peerId) ? inputsByPlayer[peerId] : NEUTRAL_INPUT,
    ));
  }
  return Object.freeze(normalized);
}

export function cloneStateForNextTick(previousState) {
  const nextState = cloneGameState(previousState);
  if (nextState.tick === Number.MAX_SAFE_INTEGER) {
    fail('STATE_INTEGER_OVERFLOW', 'State tick cannot be advanced safely.');
  }

  nextState.tick += 1;
  return nextState;
}

export function advanceWorldAge(state) {
  if (state.worldAge === Number.MAX_SAFE_INTEGER) {
    fail('STATE_INTEGER_OVERFLOW', 'State worldAge cannot be advanced safely.');
  }

  return { ...state, worldAge: state.worldAge + 1 };
}

export function applyPlayerMovement(state, inputsByPlayer, context) {
  const players = {};
  for (const { peerId, slot, position } of playersInSlotOrder(state)) {
    const move = inputsByPlayer[peerId].move;
    let nextPosition;
    if (move > 0 && position >= context.positionMax) {
      nextPosition = context.positionMax;
    } else if (move < 0 && position <= context.positionMin) {
      nextPosition = context.positionMin;
    } else {
      nextPosition = Math.max(
        context.positionMin,
        Math.min(context.positionMax, position + move),
      );
    }
    setOwn(players, peerId, { slot, position: nextPosition });
  }

  return { ...state, players };
}

export const gameSystems = Object.freeze([
  advanceWorldAge,
  applyPlayerMovement,
]);

export function advanceState(previousState, inputsByPlayer = {}, context = {}) {
  validateGameState(previousState);
  const normalizedContext = normalizeContext(context);
  const normalizedInputs = normalizeInputFrame(previousState, inputsByPlayer);

  return gameSystems.reduce(
    (state, system) => system(state, normalizedInputs, normalizedContext),
    cloneStateForNextTick(previousState),
  );
}
