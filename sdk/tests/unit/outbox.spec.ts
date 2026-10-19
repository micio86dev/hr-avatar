/**
 * The outbox keeps final transcript lines that could not be delivered yet, in capture order, and
 * forwards them in batches. Ported from the host-side utterance outbox: the ordering and
 * single-flight guarantees are kept; batching, stable idempotency keys, backoff and the stop
 * conditions are new.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ClientEvent } from '../../src/api'
import { HrAvatarError } from '../../src/errors'
import { Outbox, type Capture } from '../../src/outbox'

type Send = (events: ClientEvent[], options: { keepalive: boolean }) => Promise<void>

const network = () =>
  new HrAvatarError({ code: 'network_error', message: 'offline', recoverable: true })
const serverError = (status: number) =>
  new HrAvatarError({ code: 'provider_error', message: 'later', recoverable: true, status })
const closed = () =>
  new HrAvatarError({ code: 'conversation_closed', message: 'closed', status: 409 })
const unauthorized = () =>
  new HrAvatarError({ code: 'client_secret_expired', message: 'expired', status: 401 })

const line = (text: string, role: 'user' | 'avatar' = 'user'): Capture => ({
  type: 'utterance',
  role,
  text,
})

function setup(overrides: { enabled?: boolean } = {}) {
  const send = vi.fn<Send>().mockResolvedValue(undefined)
  const onError = vi.fn<(error: HrAvatarError) => void>()
  const target = new EventTarget()
  const outbox = new Outbox({ send, onError, target, ...overrides })
  return { send, onError, target, outbox }
}

/** Lets already-resolved promises run without moving the clock. */
const settle = () => vi.advanceTimersByTimeAsync(0)

const texts = (events: ClientEvent[]) =>
  events.map((event) => (event.type === 'utterance' ? event.text : event.name))

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(new Date('2026-10-19T10:00:00.000Z'))
})

afterEach(() => {
  vi.useRealTimers()
})

describe('capture', () => {
  it('stamps a unique idempotency key and the observed time at capture', async () => {
    const { outbox, send } = setup()

    outbox.enqueue(line('a'))
    vi.setSystemTime(new Date('2026-10-19T10:00:01.000Z'))
    outbox.enqueue(line('b'))
    await vi.advanceTimersByTimeAsync(2000)

    const events = send.mock.calls[0]?.[0] as ClientEvent[]
    expect(events[0]?.occurred_at).toBe('2026-10-19T10:00:00.000Z')
    expect(events[1]?.occurred_at).toBe('2026-10-19T10:00:01.000Z')
    expect(new Set(events.map((e) => e.idempotency_key)).size).toBe(2)
    expect(events[0]?.idempotency_key).toMatch(/^[0-9a-f-]{36}$/)
  })

  it('keeps an explicit occurred_at', async () => {
    const { outbox, send } = setup()

    outbox.enqueue({ ...line('a'), occurred_at: '2026-10-19T09:59:00.000Z' })
    await vi.advanceTimersByTimeAsync(2000)

    expect((send.mock.calls[0]?.[0] as ClientEvent[])[0]?.occurred_at).toBe(
      '2026-10-19T09:59:00.000Z',
    )
  })

  it('counts what it holds', () => {
    const { outbox } = setup()
    expect(outbox.size).toBe(0)

    outbox.enqueue(line('a'))
    outbox.enqueue(line('b'))

    expect(outbox.size).toBe(2)
  })
})

describe('batching', () => {
  it('posts one batch of 20 as soon as the 20th event is queued', async () => {
    const { outbox, send } = setup()

    for (let i = 0; i < 19; i += 1) outbox.enqueue(line(`l${i}`))
    await settle()
    expect(send).not.toHaveBeenCalled()

    outbox.enqueue(line('l19'))
    await settle()

    expect(send).toHaveBeenCalledTimes(1)
    expect(send.mock.calls[0]?.[0]).toHaveLength(20)
    expect(outbox.size).toBe(0)
  })

  it('posts a batch 2 seconds after the first queued event, not the last', async () => {
    const { outbox, send } = setup()

    outbox.enqueue(line('first'))
    await vi.advanceTimersByTimeAsync(1500)
    outbox.enqueue(line('second'))
    await vi.advanceTimersByTimeAsync(499)
    expect(send).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1)

    expect(send).toHaveBeenCalledTimes(1)
    expect(texts(send.mock.calls[0]?.[0] as ClientEvent[])).toEqual(['first', 'second'])
  })

  it('delivers in capture order, lifecycle and utterances alike', async () => {
    const { outbox, send } = setup()

    outbox.enqueue({ type: 'lifecycle', name: 'live' })
    outbox.enqueue(line('hello', 'avatar'))
    outbox.enqueue(line('hi'))
    await vi.advanceTimersByTimeAsync(2000)

    expect(texts(send.mock.calls[0]?.[0] as ClientEvent[])).toEqual(['live', 'hello', 'hi'])
  })

  it('does not use keepalive for ordinary flushes', async () => {
    const { outbox, send } = setup()

    outbox.enqueue(line('a'))
    await vi.advanceTimersByTimeAsync(2000)

    expect(send.mock.calls[0]?.[1]).toEqual({ keepalive: false })
  })

  it('never posts more than 100 events at once', async () => {
    const { outbox, send } = setup()
    send.mockRejectedValueOnce(network())

    for (let i = 0; i < 150; i += 1) outbox.enqueue(line(`l${i}`))
    await vi.advanceTimersByTimeAsync(1000)

    const sizes = send.mock.calls.map(([events]) => events.length)
    // The first attempt (100) fails and is retried whole, then the remaining 50 follow.
    expect(sizes).toEqual([100, 100, 50])
    expect(outbox.size).toBe(0)
  })
})

describe('single flight and ordering', () => {
  it('never sends an event twice when a second flush starts while the first is sending', async () => {
    const { outbox, send } = setup()
    let release: () => void = () => undefined
    send.mockImplementationOnce(() => new Promise<void>((resolve) => (release = resolve)))

    outbox.enqueue(line('a'))
    const first = outbox.flush()
    const second = outbox.flush()
    await settle()
    release()
    await Promise.all([first, second])

    expect(send).toHaveBeenCalledTimes(1)
    expect(outbox.size).toBe(0)
  })

  it('sends events captured during a send after it, never before', async () => {
    const { outbox, send } = setup()
    let release: () => void = () => undefined
    send.mockImplementationOnce(() => new Promise<void>((resolve) => (release = resolve)))

    outbox.enqueue(line('a'))
    const pending = outbox.flush()
    await settle()
    outbox.enqueue(line('b'))
    release()
    await pending
    await vi.advanceTimersByTimeAsync(2000)

    expect(send.mock.calls.map(([events]) => texts(events))).toEqual([['a'], ['b']])
  })
})

describe('retry', () => {
  it.each([
    ['a network failure', network],
    ['a 500', () => serverError(500)],
    ['a 503', () => serverError(503)],
    ['a 429', () => serverError(429)],
  ])('retries after %s with the same events and keys', async (_name, failure) => {
    const { outbox, send } = setup()
    send.mockRejectedValueOnce(failure())

    outbox.enqueue(line('a'))
    outbox.enqueue(line('b'))
    await vi.advanceTimersByTimeAsync(2000)
    expect(send).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(999)
    expect(send).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(1)

    expect(send).toHaveBeenCalledTimes(2)
    expect(send.mock.calls[1]?.[0]).toEqual(send.mock.calls[0]?.[0])
    expect(outbox.size).toBe(0)
  })

  it('backs off 1 s, 2 s, 4 s ... capped at 30 s', async () => {
    const { outbox, send } = setup()
    send.mockRejectedValue(network())
    outbox.enqueue(line('a'))
    await vi.advanceTimersByTimeAsync(2000)

    let calls = 1
    for (const delay of [1000, 2000, 4000, 8000, 16000, 30000, 30000]) {
      await vi.advanceTimersByTimeAsync(delay - 1)
      expect(send).toHaveBeenCalledTimes(calls)
      await vi.advanceTimersByTimeAsync(1)
      calls += 1
      expect(send).toHaveBeenCalledTimes(calls)
    }
  })

  it('starts the backoff over after a success', async () => {
    const { outbox, send } = setup()
    send.mockRejectedValueOnce(network()).mockRejectedValueOnce(network())
    outbox.enqueue(line('a'))
    await vi.advanceTimersByTimeAsync(2000 + 1000 + 2000)
    expect(send).toHaveBeenCalledTimes(3)

    send.mockRejectedValueOnce(network())
    outbox.enqueue(line('b'))
    await vi.advanceTimersByTimeAsync(2000)
    expect(send).toHaveBeenCalledTimes(4)
    await vi.advanceTimersByTimeAsync(1000)

    expect(send).toHaveBeenCalledTimes(5)
  })

  it('does not post early while a retry is pending', async () => {
    const { outbox, send } = setup()
    send.mockRejectedValueOnce(network())
    outbox.enqueue(line('a'))
    await vi.advanceTimersByTimeAsync(2000)

    for (let i = 0; i < 25; i += 1) outbox.enqueue(line(`l${i}`))
    await settle()

    expect(send).toHaveBeenCalledTimes(1)
  })
})

describe('stop conditions', () => {
  it.each([
    ['409 conversation_closed', closed],
    ['401', unauthorized],
  ])('stops forwarding and emits an error on %s', async (_name, failure) => {
    const { outbox, send, onError } = setup()
    const error = failure()
    send.mockRejectedValueOnce(error)

    outbox.enqueue(line('a'))
    await vi.advanceTimersByTimeAsync(2000)
    outbox.enqueue(line('late'))
    await vi.advanceTimersByTimeAsync(60_000)
    await outbox.flush()

    expect(send).toHaveBeenCalledTimes(1)
    expect(onError).toHaveBeenCalledTimes(1)
    expect(onError).toHaveBeenCalledWith(error)
    expect(outbox.size).toBe(0)
  })

  it('drops a batch the service refuses for good and keeps forwarding later events', async () => {
    const { outbox, send, onError } = setup()
    const refused = new HrAvatarError({ code: 'validation_failed', message: 'bad', status: 422 })
    send.mockRejectedValueOnce(refused)

    outbox.enqueue(line('bad'))
    await vi.advanceTimersByTimeAsync(2000)
    outbox.enqueue(line('good'))
    await vi.advanceTimersByTimeAsync(2000)

    expect(onError).toHaveBeenCalledWith(refused)
    expect(send.mock.calls.map(([events]) => texts(events))).toEqual([['bad'], ['good']])
  })

  it('treats a failure that is not an HrAvatarError as a refused batch', async () => {
    const { outbox, send, onError } = setup()
    send.mockRejectedValueOnce(new Error('boom'))

    outbox.enqueue(line('a'))
    await vi.advanceTimersByTimeAsync(2000)

    expect(onError).toHaveBeenCalledTimes(1)
    expect(onError.mock.calls[0]?.[0]).toMatchObject({ code: 'forwarding_failed' })
    expect(outbox.size).toBe(0)
  })
})

describe('opt-out', () => {
  it('queues and posts nothing when forwarding is disabled', async () => {
    const { outbox, send, target } = setup({ enabled: false })

    outbox.enqueue(line('a'))
    outbox.enqueue({ type: 'lifecycle', name: 'live' })
    target.dispatchEvent(new Event('pagehide'))
    await vi.advanceTimersByTimeAsync(60_000)
    await outbox.flush()

    expect(outbox.size).toBe(0)
    expect(send).not.toHaveBeenCalled()
  })
})

describe('final flush', () => {
  it('posts what is queued before flush() resolves', async () => {
    const { outbox, send } = setup()

    outbox.enqueue(line('a'))
    await outbox.flush()

    expect(texts(send.mock.calls[0]?.[0] as ClientEvent[])).toEqual(['a'])
    expect(outbox.size).toBe(0)
  })

  it('tries once even while a retry is pending, and does not schedule another', async () => {
    const { outbox, send } = setup()
    send.mockRejectedValue(network())
    outbox.enqueue(line('a'))
    await vi.advanceTimersByTimeAsync(2000)
    expect(send).toHaveBeenCalledTimes(1)

    await outbox.flush()
    expect(send).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(120_000)

    expect(send).toHaveBeenCalledTimes(2)
  })

  it('resolves when nothing is queued', async () => {
    const { outbox, send } = setup()

    await outbox.flush()

    expect(send).not.toHaveBeenCalled()
  })
})

describe('pagehide', () => {
  it('flushes at once with keepalive', async () => {
    const { outbox, send, target } = setup()

    outbox.enqueue(line('a'))
    target.dispatchEvent(new Event('pagehide'))
    await settle()

    expect(send).toHaveBeenCalledTimes(1)
    expect(send.mock.calls[0]?.[1]).toEqual({ keepalive: true })
  })
})

describe('dispose', () => {
  it('cancels the timers and ignores later events and pagehide', async () => {
    const { outbox, send, target } = setup()
    outbox.enqueue(line('a'))

    outbox.dispose()
    outbox.enqueue(line('b'))
    target.dispatchEvent(new Event('pagehide'))
    await vi.advanceTimersByTimeAsync(60_000)

    expect(send).not.toHaveBeenCalled()
    expect(outbox.size).toBe(0)
  })

  it('stops a pending retry', async () => {
    const { outbox, send } = setup()
    send.mockRejectedValue(network())
    outbox.enqueue(line('a'))
    await vi.advanceTimersByTimeAsync(2000)

    outbox.dispose()
    await vi.advanceTimersByTimeAsync(120_000)

    expect(send).toHaveBeenCalledTimes(1)
  })
})
