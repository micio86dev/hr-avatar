import { vi } from 'vitest'
import type { ClientEvent } from '../../../src/api'

const b64url = (value: object): string =>
  btoa(JSON.stringify(value)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')

/** A client secret shaped like the service's JWT. The SDK only decodes it; it never verifies. */
export const clientSecret = (claims: object = { sub: 'conv_01ABC' }): string =>
  `${b64url({ alg: 'HS256', typ: 'JWT' })}.${b64url(claims)}.signature`

export const BASE_URL = 'https://api.example.test'
export const PUBLISHABLE_KEY = 'hra_pk_test_0123456789abcdef'
export const CLIENT_SECRET = clientSecret()

export interface Recorded {
  path: string
  headers: Headers
  body: unknown
}

export interface FakeServiceOptions {
  transport?: string
  /** `ended_reason` the end route reports. */
  endedReason?: string
  /** Answer for a route, by path suffix; return undefined to use the default. */
  respond?: (suffix: 'connect' | 'events' | 'end') => Response | undefined
}

const json = (status: number, body: unknown): Response =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json' },
  })

/** Stubs `fetch` with an in-memory client API and records every request. */
export function fakeService(options: FakeServiceOptions = {}) {
  const requests: Recorded[] = []

  const fetchMock = vi.fn(async (url: string, init: RequestInit): Promise<Response> => {
    const path = new URL(url).pathname
    const suffix = path.split('/').pop() as 'connect' | 'events' | 'end'
    requests.push({
      path,
      headers: new Headers(init.headers),
      body: typeof init.body === 'string' ? JSON.parse(init.body) : undefined,
    })

    const custom = options.respond?.(suffix)
    if (custom) return custom
    if (suffix === 'connect') {
      const transport = options.transport ?? 'mock'
      return json(200, { provider: transport, transport })
    }
    if (suffix === 'events') return json(200, { accepted: 0, duplicates: 0 })
    return json(200, {
      id: 'conv_01ABC',
      status: 'ended',
      ended_reason: options.endedReason ?? 'client',
    })
  })
  vi.stubGlobal('fetch', fetchMock)

  const called = (suffix: string) => requests.filter((r) => r.path.endsWith(`/${suffix}`))

  return {
    requests,
    fetchMock,
    called,
    /** Every event posted so far, in order. */
    events: (): ClientEvent[] =>
      called('events').flatMap((r) => (r.body as { events: ClientEvent[] }).events),
  }
}

export const problem = (status: number, code: string, detail = 'Nope.'): Response =>
  json(status, { code, detail, request_id: 'req-1' })
