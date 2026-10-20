/**
 * The build produces every output the package promises: ESM, CJS, the minified IIFE with the
 * `HrAvatar` global, and type declarations for the main and mock entries, without the optional
 * provider libraries inlined. The build runs here so the checks hold in any CI order.
 */
import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { runInThisContext } from 'node:vm'
import { beforeAll, describe, expect, it } from 'vitest'

const ROOT = process.cwd()
const DIST = join(ROOT, 'dist')
const read = (...parts: string[]) => readFileSync(join(...parts), 'utf8')

beforeAll(() => {
  execFileSync('bun', ['run', 'build'], { cwd: ROOT, stdio: 'pipe' })
}, 120_000)

describe('outputs exist', () => {
  it.each([
    'index.js',
    'index.cjs',
    'index.d.ts',
    'index.d.cts',
    'mock.js',
    'mock.cjs',
    'mock.d.ts',
    'mock.d.cts',
    'hr-avatar.global.js',
  ])('dist/%s', (file) => {
    expect(existsSync(join(DIST, file))).toBe(true)
  })
})

describe('the IIFE', () => {
  const code = () => read(DIST, 'hr-avatar.global.js')

  it('is minified', () => {
    // A readable bundle has hundreds of lines; a minified one has a handful.
    expect(code().split('\n').length).toBeLessThan(20)
  })

  it('exposes connect, the error type and the mock transport on window.HrAvatar', () => {
    delete (globalThis as { HrAvatar?: unknown }).HrAvatar

    // A classic script runs at global scope, as a <script> tag would; an eval would not (the file
    // starts with "use strict", which scopes an eval's top-level `var` to the eval).
    runInThisContext(code())

    const global = (globalThis as unknown as { HrAvatar: Record<string, unknown> }).HrAvatar
    expect(global.connect).toBeTypeOf('function')
    expect(global.HrAvatarError).toBeTypeOf('function')
    expect(global.createMockTransport).toBeTypeOf('function')
  })
})

describe('peer libraries are not inlined', () => {
  // A string that only the library's own code contains, checked to exist in the package first so
  // the test cannot pass by searching for something that was never there.
  const libraries = [
    { pkg: '@daily-co/daily-js', file: 'dist/daily-esm.js', marker: 'DailyIframe' },
    { pkg: '@heygen/liveavatar-web-sdk', file: 'lib/index.esm.js', marker: 'avatar.start_listening' },
  ]

  it.each(libraries)('$pkg is a real library the marker identifies', ({ pkg, file, marker }) => {
    expect(read(ROOT, 'node_modules', pkg, file)).toContain(marker)
  })

  it.each(['index.js', 'index.cjs', 'mock.js', 'mock.cjs', 'hr-avatar.global.js'])(
    'dist/%s contains neither library',
    (file) => {
      const code = read(DIST, file)
      for (const { marker } of libraries) expect(code).not.toContain(marker)
    },
  )

  it('keeps the lazy imports of both libraries in the main bundle', () => {
    const code = read(DIST, 'index.js')
    expect(code).toMatch(/import\(["']@daily-co\/daily-js["']\)/)
    expect(code).toMatch(/import\(["']@heygen\/liveavatar-web-sdk["']\)/)
  })
})
