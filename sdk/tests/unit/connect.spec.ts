import { inspect } from 'node:util'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HrAvatar, HrAvatarError, createMockTransport } from '../../src'
import type { Transport } from '../../src/transports/types'
import {
  BASE_URL,
  CLIENT_SECRET,
  PUBLISHABLE_KEY,
  clientSecret,
  fakeService,
  problem,
} from './helpers/service'

const options = (extra: Record<string, unknown> = {}) => ({
  baseUrl: BASE_URL,
  publishableKey: PUBLISHABLE_KEY,
  clientSecret: CLIENT_SECRET,
  mount: document.createElement('div'),
  ...extra,
})

async function rejection(promise: Promise<unknown>): Promise<HrAvatarError> {
  const error = await promise.then(
    () => undefined,
    (e: unknown) => e,
  )
  expect(error).toBeInstanceOf(HrAvatarError)
  return error as HrAvatarError
}

beforeEach(() => {
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('HrAvatar.connect', () => {
  it('posts connect with both auth headers and resolves a connecting conversation', async () => {
    const service = fakeService()

    const conversation = await HrAvatar.connect(options())

    const [request] = service.called('connect')
    expect(request?.headers.get('X-HRA-Publishable-Key')).toBe(PUBLISHABLE_KEY)
    expect(request?.headers.get('Authorization')).toBe(`Bearer ${CLIENT_SECRET}`)
    expect(conversation.id).toBe('conv_01ABC')
    expect(conversation.state).toBe('connecting')
  })

  it('becomes live once the mock transport joins', async () => {
    fakeService()

    const conversation = await HrAvatar.connect(options())
    await vi.advanceTimersByTimeAsync(0)

    expect(conversation.state).toBe('live')
  })

  it('derives the conversation id from the sub claim of the token', async () => {
    const service = fakeService()

    await HrAvatar.connect(options({ clientSecret: clientSecret({ sub: 'conv_01XYZ' }) }))

    expect(service.requests[0]?.path).toBe('/v1/client/conversations/conv_01XYZ/connect')
  })

  it.each([
    ['not a jwt', 'not-a-jwt'],
    ['two segments', 'aaa.bbb'],
    ['an empty string', ''],
    ['a payload that is not base64', 'aaa.%%%.ccc'],
    ['a payload that is not JSON', `aaa.${btoa('plain text')}.ccc`],
    ['a payload without sub', clientSecret({ aud: 'hr-avatar-client' })],
    ['a numeric sub', clientSecret({ sub: 42 })],
    ['an empty sub', clientSecret({ sub: '' })],
  ])('rejects %s with invalid_client_secret and sends no request', async (_name, secret) => {
    const service = fakeService()

    const error = await rejection(HrAvatar.connect(options({ clientSecret: secret })))

    expect(error.code).toBe('invalid_client_secret')
    expect(service.fetchMock).not.toHaveBeenCalled()
  })

  it('surfaces the service error with its code and status', async () => {
    fakeService({ respond: (s) => (s === 'connect' ? problem(409, 'already_connected') : undefined) })

    const error = await rejection(HrAvatar.connect(options()))

    expect(error).toMatchObject({ code: 'already_connected', status: 409, request_id: 'req-1' })
  })

  it('renders into a <video> created inside the mount, or into a <video> mount as-is', async () => {
    fakeService()
    const div = document.createElement('div')
    const video = document.createElement('video')
    const seen: HTMLVideoElement[] = []
    const factory = () => {
      const transport = createMockTransport()
      const start = transport.start.bind(transport)
      transport.start = (o, e) => (seen.push(o.mount), start(o, e))
      return transport
    }

    await HrAvatar.connect(options({ mount: div, transports: { mock: factory } }))
    await HrAvatar.connect(options({ mount: video, transports: { mock: factory } }))

    expect(seen[0]).toBeInstanceOf(HTMLVideoElement)
    expect(seen[0]?.parentElement).toBe(div)
    expect(seen[1]).toBe(video)
  })

  it('hands the transport the join material and the chosen microphone', async () => {
    fakeService()
    const start = vi.fn().mockResolvedValue(undefined)
    const transport = { ...createMockTransport(), start } as unknown as Transport

    await HrAvatar.connect(
      options({ audioDeviceId: 'mic-2', transports: { mock: () => transport } }),
    )

    expect(start.mock.calls[0]?.[0]).toMatchObject({
      join: { transport: 'mock' },
      audioDeviceId: 'mic-2',
    })
  })
})

describe('failures after the service connected', () => {
  it('releases the provider session when the library is missing', async () => {
    const service = fakeService({ transport: 'daily' })

    const error = await rejection(
      HrAvatar.connect(
        options({ loaders: { daily: () => Promise.reject(new Error('Cannot find module')) } }),
      ),
    )

    expect(error.code).toBe('provider_library_missing')
    expect(error.message).toContain('@daily-co/daily-js')
    expect(service.called('end')).toHaveLength(1)
  })

  it('releases the provider session when the transport fails to start', async () => {
    const service = fakeService()
    const boom = new HrAvatarError({ code: 'transport_error', message: 'boom' })
    const transport = createMockTransport()
    transport.start = () => Promise.reject(boom)

    const error = await rejection(
      HrAvatar.connect(options({ transports: { mock: () => transport } })),
    )

    expect(error).toBe(boom)
    expect(service.called('end')).toHaveLength(1)
  })

  it('still reports the original error when releasing the session fails too', async () => {
    fakeService({
      transport: 'daily',
      respond: (s) => (s === 'end' ? problem(500, 'service_unavailable') : undefined),
    })

    const error = await rejection(
      HrAvatar.connect(options({ loaders: { daily: () => Promise.reject(new Error('x')) } })),
    )

    expect(error.code).toBe('provider_library_missing')
  })
})

describe('transcript forwarding option', () => {
  it('forwards by default', async () => {
    const service = fakeService()
    const mock = createMockTransport({ script: { avatarLines: ['Hello'] } })

    await HrAvatar.connect(options({ transports: { mock: () => mock } }))
    await vi.advanceTimersByTimeAsync(2000)

    expect(service.events().map((e) => e.type)).toEqual(['lifecycle', 'utterance'])
  })

  it('posts nothing to events when forwardTranscript is false', async () => {
    const service = fakeService()
    const mock = createMockTransport({ script: { avatarLines: ['Hello'] } })

    await HrAvatar.connect(
      options({ forwardTranscript: false, transports: { mock: () => mock } }),
    )
    await vi.advanceTimersByTimeAsync(60_000)

    expect(service.called('events')).toHaveLength(0)
  })
})

describe('secrets stay private', () => {
  it('keeps the publishable key and client secret off the conversation and out of the console', async () => {
    const spies = (['log', 'info', 'warn', 'error', 'debug'] as const).map((level) =>
      vi.spyOn(console, level).mockImplementation(() => undefined),
    )
    const service = fakeService()

    const conversation = await HrAvatar.connect(options())
    await vi.advanceTimersByTimeAsync(0)
    await conversation.end()

    const dump = inspect(conversation, { depth: 8, showHidden: true })
    for (const secret of [PUBLISHABLE_KEY, CLIENT_SECRET]) {
      expect(dump).not.toContain(secret)
      expect(JSON.stringify(Object.getOwnPropertyNames(conversation))).not.toContain(secret)
    }
    for (const spy of spies) expect(spy).not.toHaveBeenCalled()
    for (const { path } of service.requests) expect(path.startsWith('/v1/client/')).toBe(true)
    for (const [url] of service.fetchMock.mock.calls) {
      expect(url.startsWith(`${BASE_URL}/`)).toBe(true)
    }
  })
})
