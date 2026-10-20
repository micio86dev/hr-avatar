/**
 * The SDK is host-agnostic: no source file names a host-product concept, and the provider
 * libraries are never imported at module scope (they are optional, lazily loaded peers).
 */
import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

const BANNED =
  /\b(beai|participants?|candidates?|competenc(?:y|ies)|bars|interviews?|projects?|organi[sz]ations?|assessments?|scores?|scoring|dbSessionId|boundaryTicket|endPhrase|finalPhrase)\b/i

const SRC = join(process.cwd(), 'src')

function sourceFiles(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const path = join(dir, entry.name)
    return entry.isDirectory() ? sourceFiles(path) : path.endsWith('.ts') ? [path] : []
  })
}

describe('host vocabulary', () => {
  it('matches every banned term, case-insensitively and on word boundaries', () => {
    for (const term of ['candidate', 'Participants', 'BARS', 'interview', 'beai', 'dbSessionId']) {
      expect(BANNED.test(`const x = "${term}"`)).toBe(true)
    }
    // Word boundaries: unrelated words that merely contain a term are fine.
    for (const text of ['scorecard', 'projection', 'barsity']) {
      expect(BANNED.test(text)).toBe(false)
    }
  })

  it('does not appear anywhere in sdk/src', () => {
    const offenders = sourceFiles(SRC).flatMap((file) =>
      readFileSync(file, 'utf8')
        .split('\n')
        .flatMap((line, index) => (BANNED.test(line) ? [`${file}:${index + 1}: ${line.trim()}`] : [])),
    )

    expect(offenders).toEqual([])
  })
})

describe('provider libraries stay lazy', () => {
  it('no source file imports a provider library at module scope', () => {
    const offenders = sourceFiles(SRC).filter((file) =>
      readFileSync(file, 'utf8')
        .split('\n')
        .some(
          (line) =>
            /^\s*import\s/.test(line) &&
            !/^\s*import\s+type\s/.test(line) &&
            /@daily-co|@heygen/.test(line),
        ),
    )

    expect(offenders).toEqual([])
  })
})
