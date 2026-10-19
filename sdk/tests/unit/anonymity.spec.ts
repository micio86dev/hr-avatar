/**
 * The SDK is host-agnostic: no source file names a host-product concept, and the provider
 * libraries are never imported at module scope (they are optional, lazily loaded peers).
 */
import { readdirSync, readFileSync } from 'node:fs'
import { join, relative } from 'node:path'
import { describe, expect, it } from 'vitest'

const BANNED =
  /\b(beai|participants?|candidates?|competenc(?:y|ies)|bars|interviews?|projects?|organi[sz]ations?|assessments?|scores?|scoring|dbSessionId|boundaryTicket|endPhrase|finalPhrase)\b/i

const SRC = join(process.cwd(), 'src')

/**
 * The one reference to a banned word that is not host vocabulary: Daily's own `track-started`
 * event names the track owner with a field called `participant`. It is exempt in the Daily
 * transport only, as that exact property read; any other use of the word there, and every use in
 * any other file, is still an offender.
 */
const LIBRARY_FIELD = { file: join('transports', 'daily.ts'), read: /\bevent\?\.participant\b/g }

/** The lines of `text` (the content of `file`, relative to `src`) that name a banned term. */
function offendingLines(file: string, text: string): string[] {
  return text.split('\n').filter((line) => {
    const checked = file === LIBRARY_FIELD.file ? line.replace(LIBRARY_FIELD.read, '') : line
    return BANNED.test(checked)
  })
}

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

  it("exempts only the Daily library's own event field, only in the Daily transport", () => {
    const read = 'const owner = event?.participant as { local?: boolean } | undefined'

    expect(offendingLines(LIBRARY_FIELD.file, read)).toEqual([])
    expect(offendingLines(join('transports', 'liveavatar.ts'), read)).toEqual([read])
    expect(offendingLines(join('conversation.ts'), read)).toEqual([read])
    expect(offendingLines(LIBRARY_FIELD.file, 'const participant = 1')).toHaveLength(1)
    expect(offendingLines(LIBRARY_FIELD.file, 'send(participant)')).toHaveLength(1)
    expect(offendingLines(LIBRARY_FIELD.file, `${read}; // a candidate`)).toHaveLength(1)
  })

  it('does not appear anywhere in sdk/src', () => {
    const offenders = sourceFiles(SRC).flatMap((file) =>
      offendingLines(relative(SRC, file), readFileSync(file, 'utf8')).map((line) => `${file}: ${line.trim()}`),
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
