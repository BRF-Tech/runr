// The npm packages a bump moved that a built image takes: the root's dependencies and the given
// build packages (ci/deps/packages.sh, DEPS_NPM_BUILD_ROOTS) with everything they depend on, in
// the lockfile before or after the bump. Prints "name old → new" for each one whose version
// changed; nothing when only test and lint tooling moved (ci/deps/bump-packages.sh: next only).
//
//   node ci/deps/npm-shipped.mjs OLD_LOCK NEW_LOCK [ROOT…]
import { readFileSync } from 'node:fs'

const [oldPath, newPath, ...roots] = process.argv.slice(2)
if (!oldPath || !newPath) {
  console.error('usage: node ci/deps/npm-shipped.mjs OLD_LOCK NEW_LOCK [ROOT…]')
  process.exit(2)
}
const load = (path) => JSON.parse(readFileSync(path, 'utf8')).packages ?? {}

// the key a dependency NAME of the package at KEY resolves to: its own node_modules, then each
// enclosing one, as Node resolves it
function resolve(pkgs, key, name) {
  let dir = key
  for (;;) {
    const candidate = (dir ? `${dir}/` : '') + `node_modules/${name}`
    if (pkgs[candidate]) return candidate
    if (!dir) return null
    const i = dir.lastIndexOf('/node_modules/')
    dir = i < 0 ? '' : dir.slice(0, i)
  }
}

function reach(pkgs) {
  const seen = new Set()
  const root = pkgs[''] ?? {}
  const stack = [...Object.keys(root.dependencies ?? {}), ...roots]
    .map((name) => resolve(pkgs, '', name))
    .filter(Boolean)
  while (stack.length) {
    const key = stack.pop()
    if (seen.has(key)) continue
    seen.add(key)
    const pkg = pkgs[key] ?? {}
    const deps = { ...pkg.dependencies, ...pkg.optionalDependencies, ...pkg.peerDependencies }
    for (const name of Object.keys(deps)) {
      const dep = resolve(pkgs, key, name)
      if (dep && !seen.has(dep)) stack.push(dep)
    }
  }
  return seen
}

const before = load(oldPath)
const after = load(newPath)
const keys = new Set([...reach(before), ...reach(after)])
const lines = []
for (const key of [...keys].sort()) {
  const was = before[key]?.version ?? 'none'
  const now = after[key]?.version ?? 'none'
  if (was !== now) lines.push(`${key.replace(/^(.*\/)?node_modules\//, '')} ${was} → ${now}`)
}
if (lines.length) console.log(lines.join('\n'))
