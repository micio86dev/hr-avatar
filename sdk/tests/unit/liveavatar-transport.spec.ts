/**
 * The LiveAvatar transport, over an injected fake of the SDK's `LiveAvatarSession` (no WebRTC or
 * sockets). Ported from the BEAI HeyGen provider spec: the `voiceChat` config that lets the session
 * hear, attach on stream-ready, barge-in, transcription events, microphone, verbatim speech and
 * disconnects. The phrase-driven completion and the absent-phrase guard are gone: the conversation
 * owns completion.
 */
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HrAvatarError } from '../../src/errors'
import { createLiveAvatarTransport } from '../../src/transports/liveavatar'
import { SPEECH_PENDING_MS } from '../../src/transports/speech'
import { transportEvents } from './helpers/daily'
import { SESSION_TOKEN, fakeLiveAvatar, startedLiveAvatar, videoMount } from './helpers/liveavatar'

const joinMaterial = { provider: 'heygen', transport: 'liveavatar', session_token: SESSION_TOKEN }

beforeEach(() => {
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
})

async function rejection(promise: Promise<unknown>): Promise<HrAvatarError> {
  const error = await promise.then(
    () => undefined,
    (e: unknown) => e,
  )
  expect(error).toBeInstanceOf(HrAvatarError)
  return error as HrAvatarError
}

describe('the SDK event names', () => {
  // The names are string literals, not imports of the SDK's enums, so the SDK is never evaluated
  // at module scope. They are pinned here against the shipped declarations.
  const declarations = readFileSync(
    join(
      process.cwd(),
      'node_modules/@heygen/liveavatar-web-sdk/lib/LiveAvatarSession/events.d.ts',
    ),
    'utf8',
  )

  it.each([
    'session.stream_ready',
    'session.disconnected',
    'user.speak_started',
    'avatar.speak_started',
    'avatar.speak_ended',
    'user.transcription',
    'user.transcription.chunk',
    'avatar.transcription',
    'avatar.transcription.chunk',
  ])('%s is a name the installed SDK declares', (name) => {
    expect(declarations).toContain(`"${name}"`)
  })
})

describe('joining', () => {
  it('builds the session with a CONVERSATIONAL, unmuted voiceChat config', async () => {
    // The SDK publishes the local microphone ONLY when `voiceChat` is truthy: without it the avatar
    // can speak but never hear.
    const { LiveAvatarSession } = await startedLiveAvatar()

    expect(LiveAvatarSession).toHaveBeenCalledExactlyOnceWith(SESSION_TOKEN, {
      voiceChat: { mode: 'CONVERSATIONAL', defaultMuted: false },
    })
  })

  it('threads the chosen microphone through as deviceId', async () => {
    const { LiveAvatarSession } = await startedLiveAvatar({ audioDeviceId: 'mic-42' })

    expect(LiveAvatarSession).toHaveBeenCalledWith(SESSION_TOKEN, {
      voiceChat: { mode: 'CONVERSATIONAL', defaultMuted: false, deviceId: 'mic-42' },
    })
  })

  it('starts the session once and reports ready once', async () => {
    const { session, handlers } = await startedLiveAvatar()

    expect(session.start).toHaveBeenCalledTimes(1)
    expect(handlers.ready).toHaveBeenCalledTimes(1)
  })

  it('attaches the media on stream-ready, not eagerly after start', async () => {
    const { session, mount, fire, handlers } = await startedLiveAvatar()
    expect(session.attach).not.toHaveBeenCalled()

    fire('session.stream_ready')

    expect(session.attach).toHaveBeenCalledExactlyOnceWith(mount)
    expect(mount.play).toHaveBeenCalledTimes(1)
    expect(handlers.ready).toHaveBeenCalledTimes(1)
  })

  it('reports ready on stream-ready even before start has resolved', async () => {
    const fake = fakeLiveAvatar()
    const events = transportEvents()
    let finish: () => void = () => undefined
    fake.session.start.mockReturnValue(new Promise<void>((resolve) => (finish = resolve)))

    const started = createLiveAvatarTransport(fake.library).start(
      { join: joinMaterial, mount: videoMount() },
      events.handlers,
    )
    await Promise.resolve()
    fake.fire('session.stream_ready')
    expect(events.handlers.ready).toHaveBeenCalledTimes(1)

    finish()
    await started

    expect(events.handlers.ready).toHaveBeenCalledTimes(1)
  })

  it('survives a play() that rejects (autoplay policy)', async () => {
    const { mount, fire } = await startedLiveAvatar()
    mount.play = vi.fn().mockRejectedValue(new Error('NotAllowedError'))

    expect(() => fire('session.stream_ready')).not.toThrow()
    await vi.advanceTimersByTimeAsync(0)
  })

  it('survives a mount without play() (older engines)', async () => {
    const { mount, fire } = await startedLiveAvatar()
    ;(mount as { play?: unknown }).play = undefined

    expect(() => fire('session.stream_ready')).not.toThrow()
  })
})

describe('start failures', () => {
  it.each([undefined, null, ''])(
    'rejects without building a session when the session token is %j',
    async (session_token) => {
      const fake = fakeLiveAvatar()

      const error = await rejection(
        createLiveAvatarTransport(fake.library).start(
          { join: { ...joinMaterial, session_token }, mount: videoMount() },
          transportEvents().handlers,
        ),
      )

      expect(error.code).toBe('provider_unavailable')
      expect(fake.LiveAvatarSession).not.toHaveBeenCalled()
    },
  )

  it('rejects with a stable code and no vendor text when the session fails to start', async () => {
    const fake = fakeLiveAvatar()
    fake.session.start.mockRejectedValue(new Error('livekit wss://x.livekit.cloud refused'))

    const error = await rejection(
      createLiveAvatarTransport(fake.library).start(
        { join: joinMaterial, mount: videoMount() },
        transportEvents().handlers,
      ),
    )

    expect(error.code).toBe('provider_unavailable')
    expect(error.recoverable).toBe(true)
    expect(error.message).not.toMatch(/livekit|heygen|liveavatar|wss?:/i)
    expect(fake.session.stop).toHaveBeenCalledTimes(1)
  })

  it('rejects when the session cannot be constructed', async () => {
    const fake = fakeLiveAvatar()
    fake.LiveAvatarSession.mockImplementation(() => {
      throw new Error('no WebRTC')
    })

    const error = await rejection(
      createLiveAvatarTransport(fake.library).start(
        { join: joinMaterial, mount: videoMount() },
        transportEvents().handlers,
      ),
    )

    expect(error.code).toBe('provider_unavailable')
  })

  it('still rejects when releasing the failed session also fails', async () => {
    const fake = fakeLiveAvatar()
    fake.session.start.mockRejectedValue(new Error('boom'))
    fake.session.stop.mockRejectedValue(new Error('boom again'))

    const error = await rejection(
      createLiveAvatarTransport(fake.library).start(
        { join: joinMaterial, mount: videoMount() },
        transportEvents().handlers,
      ),
    )

    expect(error.code).toBe('provider_unavailable')
  })
})

describe('turn-taking and barge-in', () => {
  it('interrupts the avatar when the user starts speaking over it, and reports silence', async () => {
    const { session, fire, handlers } = await startedLiveAvatar()

    fire('avatar.speak_started')
    fire('user.speak_started')

    expect(session.interrupt).toHaveBeenCalledTimes(1)
    expect(handlers.speaking.mock.calls).toEqual([[true], [false]])
  })

  it('does NOT interrupt when the user speaks while the avatar is silent', async () => {
    const { session, fire } = await startedLiveAvatar()

    fire('user.speak_started')

    expect(session.interrupt).not.toHaveBeenCalled()
  })

  it('keeps going when interrupt throws: server-side voice detection covers barge-in', async () => {
    const { session, fire, handlers } = await startedLiveAvatar()
    session.interrupt.mockImplementation(() => {
      throw new Error('socket closed')
    })

    fire('avatar.speak_started')
    expect(() => fire('user.speak_started')).not.toThrow()

    expect(handlers.failed).not.toHaveBeenCalled()
  })
})

describe('speaking signals', () => {
  it('reports speaking on speak_started and silence on speak_ended', async () => {
    const { fire, handlers } = await startedLiveAvatar()

    fire('avatar.speak_started')
    fire('avatar.speak_ended')

    expect(handlers.speaking.mock.calls).toEqual([[true], [false]])
  })

  it('counts a transcription that arrives BEFORE its audio as speaking, before the line', async () => {
    const { fire, handlers } = await startedLiveAvatar()
    const order: string[] = []
    handlers.speaking.mockImplementation((on: boolean) => order.push(on ? 'speaking' : 'silent'))
    handlers.transcript.mockImplementation(() => order.push('line'))

    fire('avatar.transcription', { text: 'Goodbye.' })

    expect(order).toEqual(['speaking', 'line'])
  })

  it('cannot hang when the audio never starts', async () => {
    const { fire, handlers } = await startedLiveAvatar()

    fire('avatar.transcription', { text: 'Goodbye.' })
    vi.advanceTimersByTime(SPEECH_PENDING_MS)

    expect(handlers.speaking.mock.calls).toEqual([[true], [false]])
  })

  it('stays speaking through the audio that follows its transcription', async () => {
    const { fire, handlers } = await startedLiveAvatar()

    fire('avatar.transcription', { text: 'A long sentence.' })
    fire('avatar.speak_started')
    vi.advanceTimersByTime(SPEECH_PENDING_MS * 3)

    expect(handlers.speaking.mock.calls).toEqual([[true]])
  })
})

describe('transcripts', () => {
  it('reports final avatar and user lines', async () => {
    const { fire, handlers } = await startedLiveAvatar()

    fire('avatar.transcription', { text: 'Good morning.' })
    fire('user.transcription', { text: 'Hello.' })

    expect(handlers.transcript.mock.calls).toEqual([
      [{ role: 'avatar', text: 'Good morning.', final: true }],
      [{ role: 'user', text: 'Hello.', final: true }],
    ])
  })

  it('reports the chunk events as interim fragments', async () => {
    const { fire, handlers } = await startedLiveAvatar()

    fire('avatar.transcription.chunk', { text: 'Good mor' })
    fire('user.transcription.chunk', { text: 'Hel' })

    expect(handlers.transcript.mock.calls).toEqual([
      [{ role: 'avatar', text: 'Good mor', final: false }],
      [{ role: 'user', text: 'Hel', final: false }],
    ])
  })

  it('an interim avatar fragment does not by itself make the avatar speak', async () => {
    const { fire, handlers } = await startedLiveAvatar()

    fire('avatar.transcription.chunk', { text: 'Good mor' })

    expect(handlers.speaking).not.toHaveBeenCalled()
  })

  it.each([undefined, null, {}, { text: '' }, { text: 3 }])(
    'reports nothing for a transcription payload of %j',
    async (payload) => {
      const { fire, handlers } = await startedLiveAvatar()

      fire('avatar.transcription', payload)
      fire('user.transcription', payload)

      expect(handlers.transcript).not.toHaveBeenCalled()
      expect(handlers.speaking).not.toHaveBeenCalled()
    },
  )
})

describe('disconnects', () => {
  it('reports an unexpected disconnect as one close, and no failure', async () => {
    const { fire, handlers } = await startedLiveAvatar()

    fire('session.disconnected', 'SERVER_INITIATED')
    fire('session.disconnected', 'SERVER_INITIATED')

    expect(handlers.closed).toHaveBeenCalledTimes(1)
    expect(handlers.failed).not.toHaveBeenCalled()
  })

  it('a disconnect after we asked to stop is not news', async () => {
    // Observed in production: the end route returned, the transport stopped, and the provider's
    // own MAX_DURATION_REACHED landed a beat later. It must not surface as a failure.
    const { transport, fire, handlers } = await startedLiveAvatar()

    await transport.stop()
    fire('session.disconnected', 'SERVER_INITIATED')

    expect(handlers.closed).not.toHaveBeenCalled()
    expect(handlers.failed).not.toHaveBeenCalled()
  })

  it('a disconnect that lands while stop() is still in flight is ours', async () => {
    const { transport, session, fire, handlers } = await startedLiveAvatar()
    session.stop.mockImplementation(async () => fire('session.disconnected', 'CLIENT_INITIATED'))

    await transport.stop()

    expect(handlers.closed).not.toHaveBeenCalled()
  })

  // The close tells the consumer the avatar went silent (once, at the close); the cancelled window adds nothing later.
  it('a close ends the pending-speech window with one silent report', async () => {
    const { fire, handlers } = await startedLiveAvatar()
    fire('avatar.transcription', { text: 'Goodbye.' })

    fire('session.disconnected', 'SERVER_INITIATED')
    expect(handlers.speaking.mock.calls).toEqual([[true], [false]])
    vi.advanceTimersByTime(SPEECH_PENDING_MS * 2)

    expect(handlers.speaking.mock.calls).toEqual([[true], [false]])
  })

  it('reports nothing that arrives after the session is gone', async () => {
    const { fire, handlers } = await startedLiveAvatar()
    fire('session.disconnected', 'SERVER_INITIATED')

    fire('avatar.transcription', { text: 'Too late.' })
    fire('user.speak_started')

    expect(handlers.transcript).not.toHaveBeenCalled()
  })
})

describe('microphone', () => {
  it('mutes, then unmutes, through voiceChat', async () => {
    const { transport, voiceChat } = await startedLiveAvatar()

    await transport.setMicMuted(true)
    await transport.setMicMuted(false)

    expect(voiceChat.mute).toHaveBeenCalledTimes(1)
    expect(voiceChat.unmute).toHaveBeenCalledTimes(1)
  })

  it('reads the live SDK state rather than a local mirror', async () => {
    // A provider-local boolean drifts the moment anything else mutes the track (SDK-side mute,
    // device loss), and the control then does the opposite of what was asked.
    const { transport, voiceChat } = await startedLiveAvatar()
    voiceChat.isMuted = true

    await transport.setMicMuted(true)
    await transport.setMicMuted(false)

    expect(voiceChat.mute).not.toHaveBeenCalled()
    expect(voiceChat.unmute).toHaveBeenCalledTimes(1)
  })

  it.each(['mute', 'unmute'] as const)('rejects with a stable error when %s fails', async (method) => {
    const { transport, voiceChat } = await startedLiveAvatar()
    voiceChat.isMuted = method === 'unmute'
    voiceChat[method].mockRejectedValue(new Error('heygen refused'))

    const error = await rejection(transport.setMicMuted(method === 'mute'))

    expect(error.code).toBe('transport_error')
    expect(error.message).not.toMatch(/heygen/i)
  })

  it('rejects before start and after stop, like every other control', async () => {
    const transport = createLiveAvatarTransport(fakeLiveAvatar().library)
    expect((await rejection(transport.setMicMuted(true))).code).toBe('transport_error')

    const started = await startedLiveAvatar()
    await started.transport.stop()
    expect((await rejection(started.transport.setMicMuted(true))).code).toBe('transport_error')
  })
})

describe('verbatim speech and interruption', () => {
  it('say makes the avatar repeat the text verbatim', async () => {
    const { transport, session } = await startedLiveAvatar()

    await transport.say('Hello there.')

    expect(session.repeat).toHaveBeenCalledExactlyOnceWith('Hello there.')
  })

  it('interrupt stops the avatar', async () => {
    const { transport, session } = await startedLiveAvatar()

    await transport.interrupt()

    expect(session.interrupt).toHaveBeenCalledTimes(1)
  })

  it.each(['say', 'interrupt'] as const)('%s rejects before start', async (control) => {
    const transport = createLiveAvatarTransport(fakeLiveAvatar().library)

    const error = await rejection(control === 'say' ? transport.say('x') : transport.interrupt())

    expect(error.code).toBe('transport_error')
  })

  it.each(['say', 'interrupt'] as const)(
    '%s rejects with a stable error when the session throws',
    async (control) => {
      const { transport, session } = await startedLiveAvatar()
      const boom = () => {
        throw new Error('heygen socket closed')
      }
      session.repeat.mockImplementation(boom)
      session.interrupt.mockImplementation(boom)

      const error = await rejection(control === 'say' ? transport.say('x') : transport.interrupt())

      expect(error.code).toBe('transport_error')
      expect(error.message).not.toMatch(/heygen/i)
    },
  )

  it('cannot take extra context mid-conversation', async () => {
    const { transport } = await startedLiveAvatar()

    expect(transport.sendContext).toBeUndefined()
  })
})

describe('stop', () => {
  it('stops the session once and is idempotent', async () => {
    const { transport, session } = await startedLiveAvatar()

    await transport.stop()
    await transport.stop()

    expect(session.stop).toHaveBeenCalledTimes(1)
  })

  it('resolves even when the session fails to stop', async () => {
    const { transport, session } = await startedLiveAvatar()
    session.stop.mockRejectedValue(new Error('network error'))

    await expect(transport.stop()).resolves.toBeUndefined()
  })

  it('resolves before start', async () => {
    const fake = fakeLiveAvatar()

    await expect(createLiveAvatarTransport(fake.library).stop()).resolves.toBeUndefined()
    expect(fake.session.stop).not.toHaveBeenCalled()
  })

  it('cancels the pending-speech window', async () => {
    const { transport, fire, handlers } = await startedLiveAvatar()
    fire('avatar.transcription', { text: 'Goodbye.' })
    await transport.stop()
    handlers.speaking.mockClear()

    vi.advanceTimersByTime(SPEECH_PENDING_MS * 2)

    expect(handlers.speaking).not.toHaveBeenCalled()
  })
})
