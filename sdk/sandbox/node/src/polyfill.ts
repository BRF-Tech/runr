// Symbol.asyncDispose / Symbol.dispose on Node versions without explicit resource management, so
// `await using sandbox = await Sandbox.create()` works on every supported Node release. The
// well-known symbols are registered under the names TypeScript's downlevel helpers look up.
const symbols = Symbol as unknown as { asyncDispose?: symbol; dispose?: symbol }

if (typeof symbols.asyncDispose !== 'symbol') {
  Object.defineProperty(Symbol, 'asyncDispose', { value: Symbol.for('Symbol.asyncDispose') })
}
if (typeof symbols.dispose !== 'symbol') {
  Object.defineProperty(Symbol, 'dispose', { value: Symbol.for('Symbol.dispose') })
}

export {}
