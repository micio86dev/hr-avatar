/**
 * Size gate: the ESM core entry and the IIFE must each stay within 20 KB minified and gzipped.
 * Usage: `bun scripts/size.ts [dist]` (default `dist`). Exits non-zero when a file is over budget
 * or missing. The builds are minified by tsup and the ESM entry is one self-contained file, so
 * this measures what a page or an app actually loads, without the optional peer libraries.
 */
import { existsSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { gzipSync } from 'node:zlib'

/** 20 KB counted as 20,000 bytes: the stricter reading of "KB". */
const BUDGET_BYTES = 20_000
const OUTPUTS = ['index.js', 'hr-avatar.global.js']

const directory = process.argv[2] ?? 'dist'
let failed = false

for (const name of OUTPUTS) {
  const path = join(directory, name)
  if (!existsSync(path)) {
    console.error(`${name}: missing in ${directory} (run "bun run build" first)`)
    failed = true
    continue
  }
  const bytes = gzipSync(readFileSync(path)).length
  const over = bytes > BUDGET_BYTES
  console.log(`${name}: ${bytes} B gzipped${over ? ` - over the ${BUDGET_BYTES} B budget` : ''}`)
  failed ||= over
}

process.exit(failed ? 1 : 0)
