/**
 * Serves the smoke page and the built script-tag bundle. Run by Playwright (`webServer`) with
 * `bun e2e/serve.ts`; `bun run build` must have produced `dist/hr-avatar.global.js` first.
 */
import { readFile } from 'node:fs/promises'
import { createServer } from 'node:http'
import { join } from 'node:path'

const root = join(import.meta.dirname, '..')
const PORT = Number(process.env.PORT ?? 4173)

const files: Record<string, { path: string; type: string }> = {
  '/': { path: join(root, 'e2e', 'smoke.html'), type: 'text/html; charset=utf-8' },
  '/hr-avatar.global.js': {
    path: join(root, 'dist', 'hr-avatar.global.js'),
    type: 'text/javascript; charset=utf-8',
  },
}

createServer((request, response) => {
  const file = Object.hasOwn(files, request.url ?? '') ? files[request.url as string] : undefined
  if (!file) {
    response.writeHead(404).end()
    return
  }
  readFile(file.path).then(
    (body) => response.writeHead(200, { 'content-type': file.type }).end(body),
    () => response.writeHead(500).end(`Missing ${file.path}: run "bun run build" first`),
  )
}).listen(PORT, '127.0.0.1', () => console.log(`smoke server on http://127.0.0.1:${PORT}`))
