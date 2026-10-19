import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

interface Manifest {
  name?: string
  type?: string
  scripts?: Record<string, string>
  exports?: Record<string, Record<string, string>>
  peerDependencies?: Record<string, string>
  peerDependenciesMeta?: Record<string, { optional?: boolean }>
  devDependencies?: Record<string, string>
}

const manifest: Manifest = JSON.parse(
  readFileSync(join(process.cwd(), 'package.json'), 'utf8'),
)

describe('package manifest', () => {
  it('is the ESM package @hr-avatar/sdk', () => {
    expect(manifest.name).toBe('@hr-avatar/sdk')
    expect(manifest.type).toBe('module')
  })

  it.each(['.', './mock'])('exports %s with types, import and require conditions', (entry) => {
    const conditions = manifest.exports?.[entry]
    expect(conditions).toBeDefined()
    for (const condition of ['types', 'import', 'require']) {
      expect(conditions?.[condition]).toEqual(expect.any(String))
    }
  })

  it('declares both provider libraries as exact, optional peer dependencies', () => {
    expect(manifest.peerDependencies).toEqual({
      '@daily-co/daily-js': '0.91.0',
      '@heygen/liveavatar-web-sdk': '0.0.18',
    })
    expect(manifest.peerDependenciesMeta?.['@daily-co/daily-js']?.optional).toBe(true)
    expect(manifest.peerDependenciesMeta?.['@heygen/liveavatar-web-sdk']?.optional).toBe(true)
  })

  it('pins the toolchain', () => {
    const dev = manifest.devDependencies ?? {}
    expect(dev['typescript']).toBe('5.9.3')
    expect(dev['@playwright/test']).toBe('1.61.1')
    expect(dev['vitest']).toMatch(/^\^3/)
    expect(dev['@vitest/coverage-v8']).toMatch(/^\^3/)
    expect(dev['tsup']).toMatch(/^\^8/)
  })

  it('installs the provider libraries as exact dev dependencies for tests', () => {
    const dev = manifest.devDependencies ?? {}
    expect(dev['@daily-co/daily-js']).toBe('0.91.0')
    expect(dev['@heygen/liveavatar-web-sdk']).toBe('0.0.18')
  })

  it.each(['build', 'test', 'test:coverage', 'size'])('has the %s script', (script) => {
    expect(manifest.scripts?.[script]).toEqual(expect.any(String))
  })
})
