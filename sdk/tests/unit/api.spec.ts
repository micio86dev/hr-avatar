import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApi, type ClientEvent } from '../../src/api'
import { HrAvatarError } from '../../src/errors'

const BASE = 'https://api.example.test'
const PK = 'hra_pk_test_0123456789abcdef'
const CS = 'header.payload.signature'
const ID = 'conv_01ABC'

const join = { provider: 'mock', transport: 'mock' }

function respond(status: number, body: unknown, headers: Record<string, string> = {}): Response {
  const isText = typeof body === 'string'
  return new Response(isText ? body : JSON.stringify(body), {
    status,
    headers: { 'content-type': isText ? 'text/plain' : 'application/json', ...headers },
  })
}

let fetchMock: ReturnType<typeof vi.fn>

beforeEach(() => {
  fetchMock = vi.fn()
  vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

const api = () => createApi({ baseUrl: BASE, publishableKey: PK, clientSecret: CS })

async function rejection(promise: Promise<unknown>): Promise<HrAvatarError> {
  const error = await promise.then(
    () => undefined,
    (e: unknown) => e,
  )
  expect(error).toBeInstanceOf(HrAvatarError)
  return error as HrAvatarError
}

describe('requests', () => {
  it('connect posts to the client route with both auth headers and returns the join material', async () => {
    fetchMock.mockResolvedValue(respond(200, join))

    await expect(api().connect(ID)).resolves.toEqual(join)

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe(`${BASE}/v1/client/conversations/${ID}/connect`)
    expect(init.method).toBe('POST')
    const headers = new Headers(init.headers)
    expect(headers.get('X-HRA-Publishable-Key')).toBe(PK)
    expect(headers.get('Authorization')).toBe(`Bearer ${CS}`)
    expect(headers.get('Accept')).toBe('application/json')
  })

  it('tolerates a trailing slash on the base url', async () => {
    fetchMock.mockResolvedValue(respond(200, join))

    await createApi({ baseUrl: `${BASE}/`, publishableKey: PK, clientSecret: CS }).connect(ID)

    expect(fetchMock.mock.calls[0]?.[0]).toBe(`${BASE}/v1/client/conversations/${ID}/connect`)
  })

  it('never follows a redirect and never sends ambient credentials', async () => {
    fetchMock.mockResolvedValue(respond(200, join))

    await api().connect(ID)

    const init = fetchMock.mock.calls[0]?.[1] as RequestInit
    expect(init.redirect).toBe('error')
    expect(init.credentials).toBe('omit')
  })

  it('postEvents sends the batch as JSON and honours keepalive', async () => {
    fetchMock.mockResolvedValue(respond(200, { accepted: 1, duplicates: 0 }))
    const events: ClientEvent[] = [
      { idempotency_key: 'k1', type: 'lifecycle', occurred_at: '2026-10-19T10:00:00.000Z', name: 'live' },
    ]

    await api().postEvents(ID, events, { keepalive: true })

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe(`${BASE}/v1/client/conversations/${ID}/events`)
    expect(JSON.parse(init.body as string)).toEqual({ events })
    expect(new Headers(init.headers).get('Content-Type')).toBe('application/json')
    expect(init.keepalive).toBe(true)
  })

  it('postEvents does not set keepalive unless asked', async () => {
    fetchMock.mockResolvedValue(respond(200, { accepted: 0, duplicates: 0 }))

    await api().postEvents(ID, [])

    expect((fetchMock.mock.calls[0]?.[1] as RequestInit).keepalive).toBeFalsy()
  })

  it('end posts to the end route and returns the conversation resource', async () => {
    fetchMock.mockResolvedValue(respond(200, { id: ID, status: 'ended', ended_reason: 'client' }))

    await expect(api().end(ID)).resolves.toMatchObject({ status: 'ended', ended_reason: 'client' })
    expect(fetchMock.mock.calls[0]?.[0]).toBe(`${BASE}/v1/client/conversations/${ID}/end`)
  })
})

describe('service errors', () => {
  it('surfaces the Problem+JSON code, status and request id', async () => {
    fetchMock.mockResolvedValue(
      respond(409, { code: 'already_connected', detail: 'Already connected.', request_id: 'req-9' }),
    )

    const error = await rejection(api().connect(ID))

    expect(error).toMatchObject({
      code: 'already_connected',
      message: 'Already connected.',
      status: 409,
      request_id: 'req-9',
      recoverable: false,
    })
  })

  it.each(['client_secret_expired', 'client_secret_invalid', 'unauthenticated'])(
    'passes the 401 code %s through',
    async (code) => {
      fetchMock.mockResolvedValue(respond(401, { code, detail: 'No.' }))

      expect(await rejection(api().connect(ID))).toMatchObject({ code, status: 401 })
    },
  )

  it('falls back to the X-Request-Id header and the title', async () => {
    fetchMock.mockResolvedValue(
      respond(403, { code: 'forbidden', title: 'Forbidden' }, { 'X-Request-Id': 'hdr-1' }),
    )

    expect(await rejection(api().connect(ID))).toMatchObject({
      message: 'Forbidden',
      request_id: 'hdr-1',
    })
  })

  it.each([429, 500, 502, 503])('marks status %i as recoverable', async (status) => {
    fetchMock.mockResolvedValue(respond(status, { code: 'provider_error', detail: 'Later.' }))

    expect((await rejection(api().connect(ID))).recoverable).toBe(true)
  })

  it('answers a non-Problem error body with a generic code and message', async () => {
    fetchMock.mockResolvedValue(respond(502, '<html>Bad gateway</html>'))

    const error = await rejection(api().connect(ID))

    expect(error).toMatchObject({
      code: 'unexpected_response',
      message: 'Request failed (status 502)',
      status: 502,
      recoverable: true,
    })
  })

  it('rejects a successful answer that is not JSON', async () => {
    fetchMock.mockResolvedValue(respond(200, 'ok'))

    expect(await rejection(api().connect(ID))).toMatchObject({
      code: 'unexpected_response',
      status: 200,
    })
  })

  it('maps a network failure to a recoverable error', async () => {
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))

    expect(await rejection(api().connect(ID))).toMatchObject({
      code: 'network_error',
      recoverable: true,
    })
  })
})

describe('secrets stay private', () => {
  it('sends the publishable key and client secret only to the base url', async () => {
    // A fresh Response per call: a body can be read only once.
    fetchMock.mockImplementation(async () => respond(200, { accepted: 0, duplicates: 0 }))
    const client = api()

    await client.connect(ID)
    await client.postEvents(ID, [])
    await client.end(ID)

    expect(fetchMock).toHaveBeenCalledTimes(3)

    for (const [url] of fetchMock.mock.calls as [string][]) {
      expect(url.startsWith(`${BASE}/`)).toBe(true)
      expect(url).not.toContain(PK)
      expect(url).not.toContain(CS)
    }
  })

  it('does not expose them on the returned object', () => {
    const client = api()

    const visible = JSON.stringify(client) + Object.keys(client).join() + String(Object.values(client))
    expect(visible).not.toContain(PK)
    expect(visible).not.toContain(CS)
  })

  it('never logs them and keeps them out of error messages', async () => {
    const spies = (['log', 'info', 'warn', 'error', 'debug'] as const).map((level) =>
      vi.spyOn(console, level).mockImplementation(() => undefined),
    )
    fetchMock.mockResolvedValueOnce(respond(401, { code: 'client_secret_invalid', detail: 'Bad.' }))
    fetchMock.mockRejectedValueOnce(new TypeError(`boom ${CS}`))

    const first = await rejection(api().connect(ID))
    const second = await rejection(api().connect(ID))

    for (const spy of spies) expect(spy).not.toHaveBeenCalled()
    for (const error of [first, second]) {
      expect(error.message).not.toContain(CS)
      expect(error.message).not.toContain(PK)
    }
  })
})
