import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HrAvatar, HrAvatarError } from '../../src'
import type { Transport } from '../../src/transports/types'
import { open } from './helpers/open'
import {
  BASE_URL,
  CLIENT_SECRET,
  PUBLISHABLE_KEY,
  fakeService,
  problem,
} from './helpers/service'

const settle = () => vi.advanceTimersByTimeAsync(0)

async function rejection(promise: Promise<unknown>): Promise<HrAvatarError> {
  const error = await promise.then(
    () => undefined,
    (e: unknown) => e,
  )
  expect(error).toBeInstanceOf(HrAvatarError)
  return error as HrAvatarError
}

async function live(script?: Parameters<typeof open>[0]) {
  const service = fakeService()
  const opened = await open(script)
  await settle()
  return { ...opened, service }
}

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(new Date('2026-10-19T10:00:00.000Z'))
})

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('microphone', () => {
  it('toggleMic returns the new muted state synchronously and mutes the transport', async () => {
    const { conversation, mock } = await live()

    expect(conversation.toggleMic()).toBe(true)
    expect(conversation.toggleMic()).toBe(false)
    await settle()

    expect(mock.commands).toEqual([
      { type: 'mic', muted: true },
      { type: 'mic', muted: false },
    ])
  })

  it('setMicMuted asserts a state, so a later toggle flips from it', async () => {
    const { conversation, mock } = await live()

    await conversation.setMicMuted(true)
    await conversation.setMicMuted(true)

    expect(conversation.toggleMic()).toBe(false)
    expect(mock.commands.filter((c) => c.type === 'mic')).toHaveLength(3)
  })

  it('reports a failing toggle as an error event instead of an unhandled rejection', async () => {
    const service = fakeService()
    const failure = new HrAvatarError({ code: 'transport_error', message: 'no mic' })
    const stub: Transport = {
      start: async (_options, events) => void setTimeout(events.ready, 0),
      stop: async () => undefined,
      setMicMuted: () => Promise.reject(failure),
      say: async () => undefined,
      interrupt: async () => undefined,
    }
    const conversation = await HrAvatar.connect({
      baseUrl: BASE_URL,
      publishableKey: PUBLISHABLE_KEY,
      clientSecret: CLIENT_SECRET,
      mount: document.createElement('div'),
      transports: { mock: () => stub },
    })
    const errors: HrAvatarError[] = []
    conversation.on('error', (e) => errors.push(e))

    conversation.toggleMic()
    await settle()

    expect(errors).toEqual([failure])
    expect(service.called('end')).toHaveLength(0)
  })

  it('treats a transport that throws synchronously like one that rejects', async () => {
    fakeService()
    let refuse = true
    const failure = new HrAvatarError({ code: 'transport_error', message: 'no mic' })
    const stub: Transport = {
      start: async (_options, events) => void setTimeout(events.ready, 0),
      stop: async () => undefined,
      setMicMuted: (): Promise<void> => {
        if (refuse) throw failure
        return Promise.resolve()
      },
      say: async () => undefined,
      interrupt: async () => undefined,
    }
    const conversation = await HrAvatar.connect({
      baseUrl: BASE_URL,
      publishableKey: PUBLISHABLE_KEY,
      clientSecret: CLIENT_SECRET,
      mount: document.createElement('div'),
      transports: { mock: () => stub },
    })
    const errors: HrAvatarError[] = []
    conversation.on('error', (e) => errors.push(e))

    // toggleMic answers at once and reports the failure as an error event, it never throws.
    expect(conversation.toggleMic()).toBe(true)
    await settle()
    expect(errors).toEqual([failure])

    // setMicMuted rejects with the failure.
    await expect(conversation.setMicMuted(true)).rejects.toBe(failure)

    // Nothing stays in flight: the host's view is back on the device state (unmuted), so the
    // next toggle flips from the truth.
    refuse = false
    expect(conversation.toggleMic()).toBe(true)
  })
})

describe('a transport that fails with a plain error', () => {
  it('is reported as a transport_error without leaking the original message', async () => {
    fakeService()
    const stub: Transport = {
      start: async () => undefined,
      stop: async () => undefined,
      setMicMuted: () => Promise.reject(new Error('secret device label')),
      say: async () => undefined,
      interrupt: async () => undefined,
    }
    const conversation = await HrAvatar.connect({
      baseUrl: BASE_URL,
      publishableKey: PUBLISHABLE_KEY,
      clientSecret: CLIENT_SECRET,
      mount: document.createElement('div'),
      transports: { mock: () => stub },
    })
    const errors: HrAvatarError[] = []
    conversation.on('error', (e) => errors.push(e))

    conversation.toggleMic()
    await settle()

    expect(errors[0]?.code).toBe('transport_error')
    expect(errors[0]?.message).not.toContain('secret device label')
  })
})

describe('speech and context', () => {
  it('say makes the transport speak the text verbatim', async () => {
    const { conversation, mock } = await live()

    await conversation.say('hello')

    expect(mock.commands).toContainEqual({ type: 'say', text: 'hello' })
  })

  it('interrupt stops the current speech', async () => {
    const { conversation, mock } = await live()

    await conversation.interrupt()

    expect(mock.commands).toContainEqual({ type: 'interrupt' })
  })

  it('sendContext delivers a string as-is and an object as JSON', async () => {
    const { conversation, mock } = await live()

    await conversation.sendContext('the customer is upset')
    await conversation.sendContext({ a: 1 })

    expect(mock.commands).toEqual([
      { type: 'context', payload: 'the customer is upset' },
      { type: 'context', payload: '{"a":1}' },
    ])
  })

  it('rejects with unsupported_operation when the transport cannot take context', async () => {
    fakeService()
    const stub: Transport = {
      start: async () => undefined,
      stop: async () => undefined,
      setMicMuted: async () => undefined,
      say: async () => undefined,
      interrupt: async () => undefined,
    }
    const conversation = await HrAvatar.connect({
      baseUrl: BASE_URL,
      publishableKey: PUBLISHABLE_KEY,
      clientSecret: CLIENT_SECRET,
      mount: document.createElement('div'),
      transports: { mock: () => stub },
    })

    const error = await rejection(conversation.sendContext({ a: 1 }))

    expect(error.code).toBe('unsupported_operation')
  })
})

describe('whenAvatarSilent', () => {
  it('resolves at once when the avatar has not spoken', async () => {
    const { conversation } = await live()

    await expect(conversation.whenAvatarSilent(1000)).resolves.toBeUndefined()
  })

  it('resolves at once when the avatar has already been silent that long', async () => {
    const { conversation, mock } = await live()
    mock.startSpeaking()
    mock.stopSpeaking()
    await vi.advanceTimersByTimeAsync(3000)

    await expect(conversation.whenAvatarSilent(1000)).resolves.toBeUndefined()
  })

  it('waits for the speech to stop and then for the silence to last', async () => {
    const { conversation, mock } = await live()
    const done = vi.fn()
    mock.startSpeaking()

    void conversation.whenAvatarSilent(1000).then(done)
    await vi.advanceTimersByTimeAsync(5000)
    expect(done).not.toHaveBeenCalled()

    mock.stopSpeaking()
    await vi.advanceTimersByTimeAsync(999)
    expect(done).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1)

    expect(done).toHaveBeenCalledTimes(1)
  })

  it('starts counting again when the avatar resumes before the silence is long enough', async () => {
    const { conversation, mock } = await live()
    const done = vi.fn()
    mock.startSpeaking()
    mock.stopSpeaking()

    void conversation.whenAvatarSilent(1000).then(done)
    await vi.advanceTimersByTimeAsync(600)
    mock.startSpeaking()
    await vi.advanceTimersByTimeAsync(5000)
    expect(done).not.toHaveBeenCalled()
    mock.stopSpeaking()
    await vi.advanceTimersByTimeAsync(1000)

    expect(done).toHaveBeenCalledTimes(1)
  })

  it('resolves when the conversation ends first, and never hangs afterwards', async () => {
    const { conversation, mock } = await live()
    const done = vi.fn()
    mock.startSpeaking()
    void conversation.whenAvatarSilent(60_000).then(done)

    await conversation.end()

    expect(done).toHaveBeenCalledTimes(1)
    await expect(conversation.whenAvatarSilent(1000)).resolves.toBeUndefined()
  })
})

describe('after the end', () => {
  it('rejects every asynchronous control with conversation_ended', async () => {
    const { conversation } = await live()
    await conversation.end()

    for (const call of [
      () => conversation.say('x'),
      () => conversation.interrupt(),
      () => conversation.setMicMuted(true),
      () => conversation.sendContext('x'),
    ]) {
      expect((await rejection(call())).code).toBe('conversation_ended')
    }
  })

  it('throws conversation_ended from toggleMic', async () => {
    const { conversation } = await live()
    await conversation.end()

    expect(() => conversation.toggleMic()).toThrow(
      expect.objectContaining({ code: 'conversation_ended' }),
    )
  })

  it('treats a conversation that is still ending as ended', async () => {
    const { conversation } = await live()

    const ending = conversation.end()

    expect((await rejection(conversation.say('x'))).code).toBe('conversation_ended')
    await ending
  })
})

describe('end', () => {
  it('calls the service once however often it is called, and stops the transport', async () => {
    const { conversation, mock, service } = await live()

    await Promise.all([conversation.end(), conversation.end()])
    await conversation.end()

    expect(service.called('end')).toHaveLength(1)
    expect(mock.commands.filter((c) => c.type === 'stop')).toHaveLength(1)
  })

  it('posts what is still queued before it ends the conversation', async () => {
    const { conversation, mock, service } = await live()
    mock.emitUserLine('last words')

    await conversation.end()

    const paths = service.requests.map((r) => r.path.split('/').pop())
    expect(paths.lastIndexOf('events')).toBeLessThan(paths.indexOf('end'))
    expect(service.events().map((e) => e.type === 'utterance' && e.text)).toContain('last words')
  })

  it('releases the media tracks', async () => {
    fakeService()
    const stop = vi.fn()
    class FakeStream {
      getTracks = () => [{ stop }, { stop }]
    }
    vi.stubGlobal('MediaStream', FakeStream)
    const video = document.createElement('video')
    video.srcObject = new FakeStream() as unknown as MediaStream
    const { conversation } = await open(undefined, { mount: video })

    await conversation.end()

    expect(stop).toHaveBeenCalledTimes(2)
    expect(video.srcObject).toBeNull()
  })

  it('still ends locally, and reports the failure, when the service cannot be reached', async () => {
    fakeService({ respond: (s) => (s === 'end' ? problem(503, 'service_unavailable') : undefined) })
    const { conversation, mock } = await open()
    await settle()
    const errors: HrAvatarError[] = []
    const ended = vi.fn()
    conversation.on('error', (e) => errors.push(e))
    conversation.on('ended', ended)

    await expect(conversation.end()).resolves.toBeUndefined()

    expect(errors[0]).toMatchObject({ code: 'service_unavailable', status: 503 })
    expect(ended).toHaveBeenCalledWith({ reason: 'user' })
    expect(mock.commands).toContainEqual({ type: 'stop' })
  })
})
