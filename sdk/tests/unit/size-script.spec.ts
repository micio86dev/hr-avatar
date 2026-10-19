/**
 * `bun run size` fails the build when the ESM core entry or the IIFE exceeds 20 KB minified and
 * gzipped. Fixture directories stand in for `dist`, so the gate is proven without a build.
 */
import { spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { afterAll, describe, expect, it } from 'vitest'

const SCRIPT = join(process.cwd(), 'scripts', 'size.ts')
const roots: string[] = []

/** Random bytes do not compress, so the gzipped size is about the raw size. */
const bulky = (bytes: number) => `export default "${randomBytes(bytes).toString('base64')}"\n`
const small = 'export const a = 1\n'

function dist(files: Record<string, string>): string {
  const root = mkdtempSync(join(tmpdir(), 'size-gate-'))
  roots.push(root)
  mkdirSync(root, { recursive: true })
  for (const [name, content] of Object.entries(files)) writeFileSync(join(root, name), content)
  return root
}

function run(directory: string) {
  const result = spawnSync('bun', [SCRIPT, directory], { encoding: 'utf8' })
  return { code: result.status, out: `${result.stdout}${result.stderr}` }
}

afterAll(() => {
  for (const root of roots) rmSync(root, { recursive: true, force: true })
})

describe('size gate', () => {
  it('passes, and reports both sizes, when the ESM core and the IIFE are under budget', () => {
    const { code, out } = run(dist({ 'index.js': small, 'hr-avatar.global.js': small }))

    expect(code).toBe(0)
    expect(out).toContain('index.js')
    expect(out).toContain('hr-avatar.global.js')
  })

  it('fails when the ESM core entry is over budget', () => {
    const { code, out } = run(dist({ 'index.js': bulky(30_000), 'hr-avatar.global.js': small }))

    expect(code).not.toBe(0)
    expect(out).toMatch(/index\.js.*over/i)
  })

  it('fails when the IIFE is over budget', () => {
    const { code, out } = run(dist({ 'index.js': small, 'hr-avatar.global.js': bulky(30_000) }))

    expect(code).not.toBe(0)
    expect(out).toMatch(/hr-avatar\.global\.js.*over/i)
  })

  it('passes a file that is large on disk but compresses under the budget', () => {
    const { code } = run(
      dist({ 'index.js': `export default "${'a'.repeat(200_000)}"\n`, 'hr-avatar.global.js': small }),
    )

    expect(code).toBe(0)
  })

  it('fails, naming the file, when a build output is missing', () => {
    const { code, out } = run(dist({ 'index.js': small }))

    expect(code).not.toBe(0)
    expect(out).toContain('hr-avatar.global.js')
  })
})
