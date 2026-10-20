/**
 * Findings of the full-coverage reviews rv03 and rv05: an answer that is not an object, a request that
 * never settles, a teardown that waits for a service that stopped answering, a mount that cannot be
 * resolved after the service has already moved the conversation, a failed mute, a host handler failing
 * where the page has no `reportError`.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HrAvatar, HrAvatarError } from '../../src'
import { createApi } from '../../src/api'
import type { Transport } from '../../src/transports/types'
import { open } from './helpers/open'
import { BASE_URL, CLIENT_SECRET, PUBLISHABLE_KEY, fakeService } from './helpers/service'

const settle = () => vi.advanceTimersByTimeAsync(0)

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
})

const api = () => createApi({ baseUrl: BASE_URL, publishableKey: PUBLISHABLE_KEY, clientSecret: CLIENT_SECRET })

/** A fetch that never answers; it fails only when the request is aborted, as a real one does. */
function hangingFetch() {
  return vi.fn(
    (_url: string, init: RequestInit) =>
      new Promise<Response>((_resolve, reject) => {
        init.signal?.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')))
      }),
  )
}

describe('an answer that is not a JSON object', () => {
  it.each(['null', '"ok"', '7', 'true'])('is an unexpected_response, not a value returned as the result (%s)', async (body) => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(body, { status: 200 })))

    const error = await rejection(api().connect('conv_1'))

    expect(error.code).toBe('unexpected_response')
    expect(error.status).toBe(200)
  })
})

describe('a request that never settles', () => {
  it('is aborted after 15 seconds and rejects with a recoverable network_error', async () => {
    const fetchMock = hangingFetch()
    vi.stubGlobal('fetch', fetchMock)
    const pending = rejection(api().end('conv_1'))

    await vi.advanceTimersByTimeAsync(14_999)
    expect(fetchMock.mock.calls[0]?.[1].signal?.aborted).toBe(false)
    await vi.advanceTimersByTimeAsync(1)
    const error = await pending

    expect(error.code).toBe('network_error')
    expect(error.recoverable).toBe(true)
  })

  /** Headers arrive at once; the body then stalls and errors only when the request is aborted, as a real one does. */
  function stalledBody(status: number) {
    return vi.fn(async (_url: string, init: RequestInit) => {
      const body = new ReadableStream<Uint8Array>({
        start(controller) {
          init.signal?.addEventListener('abort', () => controller.error(new DOMException('aborted', 'AbortError')))
        },
      })
      return new Response(body, { status })
    })
  }

  it.each([200, 503])('gives up on a body that stalls after the headers (status %i) with the same recoverable network_error', async (status) => {
    const fetchMock = stalledBody(status)
    vi.stubGlobal('fetch', fetchMock)
    const pending = rejection(api().end('conv_1'))

    await vi.advanceTimersByTimeAsync(14_999)
    expect(fetchMock.mock.calls[0]?.[1].signal?.aborted).toBe(false)
    await vi.advanceTimersByTimeAsync(1)
    const error = await pending

    expect(error.code).toBe('network_error')
    expect(error.recoverable).toBe(true)
  })

  it('still reads a body that is not JSON as no body, not as a timeout', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response('<html>', { status: 502 })))

    const error = await rejection(api().end('conv_1'))

    expect(error.code).toBe('unexpected_response')
    expect(error.recoverable).toBe(true)
  })

  it('does not abort a request that answered in time', async () => {
    const fetchMock = vi.fn(async (_url: string, _init: RequestInit) => new Response('{"id":"c"}', { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    await api().end('conv_1')
    await vi.advanceTimersByTimeAsync(60_000)

    expect(fetchMock.mock.calls[0]?.[1].signal?.aborted).toBe(false)
  })

  it('lets a conversation end, release its media and say so even though the service never answers', async () => {
    const respond = fakeService()
    const answer = respond.fetchMock.getMockImplementation()
    const hang = hangingFetch()
    vi.stubGlobal('fetch', vi.fn((url: string, init: RequestInit) => (url.endsWith('/end') ? hang(url, init) : answer?.(url, init))))
    const stop = vi.fn(async () => undefined)
    const transport: Transport = {
      start: async (_options, events) => void setTimeout(events.ready, 0),
      stop,
      setMicMuted: async () => undefined,
      say: async () => undefined,
      interrupt: async () => undefined,
    }
    const conversation = await HrAvatar.connect({
      baseUrl: BASE_URL,
      publishableKey: PUBLISHABLE_KEY,
      clientSecret: CLIENT_SECRET,
      mount: document.createElement('div'),
      transports: { mock: () => transport },
    })
    await settle()
    const ended = vi.fn()
    const errors: HrAvatarError[] = []
    conversation.on('ended', ended)
    conversation.on('error', (e) => errors.push(e))

    void conversation.end()
    await vi.advanceTimersByTimeAsync(15_000)

    expect(stop).toHaveBeenCalledTimes(1)
    expect(ended).toHaveBeenCalledWith({ reason: 'user' })
    expect(errors.map((e) => e.code)).toEqual(['network_error'])
    expect(conversation.state).toBe('ended')
  })
})

describe('a mount that cannot be resolved', () => {
  it('releases the provider session the service already holds', async () => {
    const service = fakeService()

    await expect(
      HrAvatar.connect({
        baseUrl: BASE_URL,
        publishableKey: PUBLISHABLE_KEY,
        clientSecret: CLIENT_SECRET,
        mount: null as unknown as HTMLElement,
        transports: { mock: () => ({}) as Transport },
      }),
    ).rejects.toThrow()

    expect(service.called('end')).toHaveLength(1)
  })
})

describe('a microphone control the transport refuses', () => {
  it('leaves the muted state as it was, so the next toggle flips from the truth', async () => {
    fakeService()
    let refuse = true
    const transport: Transport = {
      start: async (_options, events) => void setTimeout(events.ready, 0),
      stop: async () => undefined,
      setMicMuted: () => (refuse ? Promise.reject(new Error('no mic')) : Promise.resolve()),
      say: async () => undefined,
      interrupt: async () => undefined,
    }
    const conversation = await HrAvatar.connect({
      baseUrl: BASE_URL,
      publishableKey: PUBLISHABLE_KEY,
      clientSecret: CLIENT_SECRET,
      mount: document.createElement('div'),
      transports: { mock: () => transport },
    })
    await settle()
    conversation.on('error', () => undefined)

    expect(conversation.toggleMic()).toBe(true)
    await settle()
    refuse = false

    expect(conversation.toggleMic()).toBe(true)
  })
})

describe('overlapping microphone toggles', () => {
  /** A transport whose mute calls stay pending until the test settles them, in any order. */
  async function openWithPendingMute() {
    fakeService()
    const calls: Array<{ muted: boolean; accept: () => void; refuse: () => void }> = []
    const transport: Transport = {
      start: async (_options, events) => void setTimeout(events.ready, 0),
      stop: async () => undefined,
      setMicMuted: (muted) =>
        new Promise<void>((resolve, reject) => {
          calls.push({ muted, accept: resolve, refuse: () => reject(new Error('no mic')) })
        }),
      say: async () => undefined,
      interrupt: async () => undefined,
    }
    const conversation = await HrAvatar.connect({
      baseUrl: BASE_URL,
      publishableKey: PUBLISHABLE_KEY,
      clientSecret: CLIENT_SECRET,
      mount: document.createElement('div'),
      transports: { mock: () => transport },
    })
    await settle()
    conversation.on('error', () => undefined)
    return { conversation, calls }
  }

  /** The state the next toggle would report is the state the device is believed to be in, inverted. */
  async function nextToggleAfter(conversation: Awaited<ReturnType<typeof openWithPendingMute>>['conversation'], calls: Array<{ accept: () => void }>) {
    await settle()
    const result = conversation.toggleMic()
    calls.at(-1)?.accept()
    await settle()
    return result
  }

  it('returns to the device state when both are refused, whatever order they settle in', async () => {
    const { conversation, calls } = await openWithPendingMute()

    expect(conversation.toggleMic()).toBe(true)
    expect(conversation.toggleMic()).toBe(false)
    calls[0]?.refuse()
    calls[1]?.refuse()

    expect(await nextToggleAfter(conversation, calls)).toBe(true)
  })

  it('keeps the confirmed state when the first is accepted and the second refused', async () => {
    const { conversation, calls } = await openWithPendingMute()

    conversation.toggleMic()
    conversation.toggleMic()
    calls[1]?.refuse()
    calls[0]?.accept()

    expect(await nextToggleAfter(conversation, calls)).toBe(false)
  })

  it('takes the later request as the device state when the earlier one settles last', async () => {
    const { conversation, calls } = await openWithPendingMute()

    conversation.toggleMic()
    conversation.toggleMic()
    calls[1]?.accept()
    calls[0]?.accept()

    expect(await nextToggleAfter(conversation, calls)).toBe(true)
  })
})

describe('a host handler that throws on a page without reportError', () => {
  it('does not stop the other handlers, and the failure is thrown on its own turn instead of being lost', async () => {
    fakeService()
    const { conversation, mock } = await open()
    await settle()
    vi.stubGlobal('reportError', undefined)
    const failure = new Error('host bug')
    const survivor = vi.fn()
    conversation.on('transcript', () => {
      throw failure
    })
    conversation.on('transcript', survivor)

    mock.emitAvatarLine('hello')

    expect(survivor).toHaveBeenCalledTimes(1)
    expect(() => vi.runOnlyPendingTimers()).toThrow(failure)
  })
})
