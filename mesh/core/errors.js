export class MeshStateError extends Error {
  constructor(code, message, options = undefined) {
    super(message, options);
    this.name = 'MeshStateError';
    this.code = code;
  }
}

export function fail(code, message) {
  throw new MeshStateError(code, message);
}
