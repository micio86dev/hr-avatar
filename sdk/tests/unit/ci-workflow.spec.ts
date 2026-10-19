/**
 * The SDK CI workflow runs every gate, in order, with Bun only and with every action pinned to a
 * release tag. The file is parsed as text: its structure is flat enough that a YAML dependency
 * would be the only reason to add one.
 */
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

const text = readFileSync(join(process.cwd(), '..', '.github', 'workflows', 'sdk.yml'), 'utf8')
const lines = text.split('\n')

const uses = [...text.matchAll(/^\s*(?:-\s*)?uses:\s*(\S+)/gm)].map((match) => match[1] as string)
const runs = [...text.matchAll(/^\s*(?:-\s*)?run:\s*(.+)$/gm)].map((match) => (match[1] as string).trim())

describe('actions', () => {
  it('uses at least the checkout, Bun and Node setup actions', () => {
    expect(uses).toEqual(
      expect.arrayContaining(['actions/checkout@v4', 'oven-sh/setup-bun@v2', 'actions/setup-node@v4']),
    )
  })

  it.each(uses)('%s is pinned to a release tag, never a branch or a bare name', (action) => {
    expect(action).toMatch(/^[\w.-]+\/[\w.-]+@v\d+(\.\d+){0,2}$/)
  })
})

describe('toolchain', () => {
  it('pins the Bun and Node versions the SDK is built and tested with', () => {
    expect(text).toMatch(/bun-version:\s*['"]?1\.4\.0['"]?\s*$/m)
    expect(text).toMatch(/node-version:\s*['"]?24['"]?\s*$/m)
  })

  it('runs everything from the sdk directory', () => {
    expect(text).toMatch(/working-directory:\s*sdk\s*$/m)
  })

  it('never uses npm, npx, pnpm or yarn', () => {
    expect(runs.filter((run) => /\b(npm|npx|pnpm|yarn)\b/.test(run))).toEqual([])
  })

  it('grants the workflow read access to the repository only', () => {
    expect(text).toMatch(/^permissions:\s*\n\s+contents:\s*read\s*$/m)
  })
})

describe('triggers', () => {
  it.each(['push', 'pull_request'])('runs on %s for changes under sdk/ and to the workflow', (event) => {
    const start = lines.findIndex((line) => new RegExp(`^  ${event}:`).test(line))
    expect(start).toBeGreaterThan(-1)
    const block = lines.slice(start + 1, start + 8).join('\n')
    expect(block).toContain("'sdk/**'")
    expect(block).toContain("'.github/workflows/sdk.yml'")
  })
})

describe('steps', () => {
  const ordered = [
    'bun install --frozen-lockfile',
    'bunx tsc --noEmit',
    'bun run test:coverage',
    'bun run build',
    'bun run size',
    'bunx playwright install --with-deps chromium webkit',
    'bunx playwright test',
  ]

  it('runs every gate, in this order', () => {
    const positions = ordered.map((command) => runs.indexOf(command))

    expect(positions).not.toContain(-1)
    expect(positions).toEqual([...positions].sort((a, b) => a - b))
  })

  it('keeps the Playwright report when the smoke fails', () => {
    expect(text).toMatch(/if:\s*failure\(\)/)
    expect(text).toContain('actions/upload-artifact@v4')
    expect(text).toContain('playwright-report')
  })
})
