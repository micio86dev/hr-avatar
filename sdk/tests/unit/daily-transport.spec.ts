/**
 * The Daily transport, over an injected fake `daily-js` call object (no WebRTC). Ported from the
 * BEAI Tavus provider spec: joining, rendering into the mount's `<video>`, transcript roles,
 * microphone, verbatim speech and teardown. The host-specific parts (boundary steering, the
 * `end_interview` tool call, phrase-driven completion) are gone: the conversation owns completion.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HrAvatarError } from '../../src/errors'
import { createDailyTransport } from '../../src/transports/daily'
import { CONVERSATION_URL, fakeDaily, startedDaily, transportEvents } from './helpers/daily'

class FakeStream {
  tracks: unknown[] = []
  addTrack(track: unknown) {
    this.tracks.push(track)
  }
}

beforeEach(() => {
  vi.stubGlobal('MediaStream', FakeStream)
})

afterEach(() => {
  vi.unstubAllGlobals()
})

async function rejection(promise: Promise<unknown>): Promise<HrAvatarError> {
  const error = await promise.then(
    () => undefined,
    (e: unknown) => e,
  )
  expect(error).toBeInstanceOf(HrAvatarError)
  return error as HrAvatarError
}

describe('joining', () => {
  it('creates an audio-only call object and joins the conversation url with video off', async () => {
    const { library, call } = await startedDaily()

    expect(library.default.createCallObject).toHaveBeenCalledWith({
      audioSource: true,
      videoSource: false,
    })
    expect(call.join).toHaveBeenCalledWith({ url: CONVERSATION_URL, startVideoOff: true })
  })

  it('opens the chosen microphone when an audioDeviceId is given', async () => {
    const { library } = await startedDaily({ audioDeviceId: 'mic-42' })

    expect(library.default.createCallObject).toHaveBeenCalledWith({
      audioSource: 'mic-42',
      videoSource: false,
    })
  })

  it('reports ready once, when the provider says the meeting was joined', async () => {
    const daily = fakeDaily()
    const { handlers } = transportEvents()
    daily.call.join.mockImplementation(async () => daily.fire('joined-meeting'))

    await createDailyTransport(daily.library).start(
      {
        join: { provider: 'tavus', transport: 'daily', conversation_url: CONVERSATION_URL },
        mount: document.createElement('video'),
      },
      handlers,
    )

    expect(handlers.ready).toHaveBeenCalledTimes(1)
  })

  it('reports ready when join resolves without a joined-meeting event', async () => {
    const { handlers } = await startedDaily()

    expect(handlers.ready).toHaveBeenCalledTimes(1)
  })

  it('does not report ready before join has resolved', async () => {
    const daily = fakeDaily()
    const { handlers } = transportEvents()
    let finish: () => void = () => undefined
    daily.call.join.mockReturnValue(new Promise<void>((resolve) => (finish = resolve)))

    const started = createDailyTransport(daily.library).start(
      {
        join: { provider: 'tavus', transport: 'daily', conversation_url: CONVERSATION_URL },
        mount: document.createElement('video'),
      },
      handlers,
    )
    await Promise.resolve()
    expect(handlers.ready).not.toHaveBeenCalled()

    finish()
    await started

    expect(handlers.ready).toHaveBeenCalledTimes(1)
  })
})

describe('start failures', () => {
  const join = { provider: 'tavus', transport: 'daily', conversation_url: CONVERSATION_URL }

  it('rejects with a stable code and a message that names no vendor when join fails', async () => {
    const daily = fakeDaily()
    daily.call.join.mockRejectedValue(new Error('daily.co room https://x.daily.co/abc refused'))

    const error = await rejection(
      createDailyTransport(daily.library).start(
        { join, mount: document.createElement('video') },
        transportEvents().handlers,
      ),
    )

    expect(error.code).toBe('provider_unavailable')
    expect(error.recoverable).toBe(true)
    expect(error.message).not.toMatch(/daily|tavus|room|https?:/i)
    expect(daily.call.destroy).toHaveBeenCalledTimes(1)
  })

  it('rejects when the call object cannot be created', async () => {
    const daily = fakeDaily()
    daily.library.default.createCallObject.mockImplementation(() => {
      throw new Error('no WebRTC')
    })

    const error = await rejection(
      createDailyTransport(daily.library).start(
        { join, mount: document.createElement('video') },
        transportEvents().handlers,
      ),
    )

    expect(error.code).toBe('provider_unavailable')
  })

  it.each([undefined, null, '', 'not a url', 'https://provider.example.test/'])(
    'rejects without creating a call object when the conversation url is %j',
    async (conversation_url) => {
      const daily = fakeDaily()

      const error = await rejection(
        createDailyTransport(daily.library).start(
          {
            join: { ...join, conversation_url: conversation_url as string },
            mount: document.createElement('video'),
          },
          transportEvents().handlers,
        ),
      )

      expect(error.code).toBe('provider_unavailable')
      expect(daily.library.default.createCallObject).not.toHaveBeenCalled()
    },
  )

  it('a failed start leaves a later stop() with nothing to tear down twice', async () => {
    const daily = fakeDaily()
    daily.call.join.mockRejectedValue(new Error('boom'))
    const transport = createDailyTransport(daily.library)
    await transport
      .start({ join, mount: document.createElement('video') }, transportEvents().handlers)
      .catch(() => undefined)

    await transport.stop()

    expect(daily.call.leave).not.toHaveBeenCalled()
    expect(daily.call.destroy).toHaveBeenCalledTimes(1)
  })
})

describe('rendering into the mount', () => {
  const track = (id: string) => ({ id }) as unknown as MediaStreamTrack

  it('builds one stream from the remote tracks and assigns it to the mount', async () => {
    const { mount, fire } = await startedDaily()

    fire('track-started', { participant: { local: false }, track: track('video') })
    const first = mount.srcObject
    fire('track-started', { participant: { local: false }, track: track('audio') })

    expect(first).toBeInstanceOf(FakeStream)
    expect(mount.srcObject).toBe(first)
    expect((mount.srcObject as unknown as FakeStream).tracks).toHaveLength(2)
  })

  it('ignores the local microphone, which would be played back at the user', async () => {
    const { mount, fire } = await startedDaily()

    fire('track-started', { participant: { local: true }, track: track('mic') })

    expect(mount.srcObject == null).toBe(true)
  })

  it('ignores a track-started event that carries no track', async () => {
    const { mount, fire } = await startedDaily()

    fire('track-started', { participant: { local: false } })
    fire('track-started', undefined)

    expect(mount.srcObject == null).toBe(true)
  })
})

describe('transcripts', () => {
  it('reports the user and the avatar as final lines', async () => {
    const { utter, handlers } = await startedDaily()

    utter('user', 'I think so.')
    utter('replica', 'Tell me more.', { inference_id: 'a' })

    expect(handlers.transcript).toHaveBeenNthCalledWith(1, {
      role: 'user',
      text: 'I think so.',
      final: true,
    })
    expect(handlers.transcript).toHaveBeenNthCalledWith(2, {
      role: 'avatar',
      text: 'Tell me more.',
      final: true,
    })
  })

  it.each([
    ['an empty speech', { event_type: 'conversation.utterance', properties: { role: 'user', speech: '' } }],
    ['no speech', { event_type: 'conversation.utterance', properties: { role: 'user' } }],
    ['no properties', { event_type: 'conversation.utterance' }],
    ['an unrelated event', { event_type: 'conversation.something_else' }],
    ['a tool call (completion is the conversation phrases, not a tool)', { type: 'conversation.tool_call', name: 'end' }],
    ['a string payload', 'plain text'],
    ['a null payload', null],
  ])('reports nothing for %s', async (_name, data) => {
    const { message, handlers } = await startedDaily()

    message(data)
    message(undefined)

    expect(handlers.transcript).not.toHaveBeenCalled()
  })
})

describe('microphone', () => {
  it('muting disables the local audio and unmuting enables it', async () => {
    const { transport, call } = await startedDaily()

    await transport.setMicMuted(true)
    await transport.setMicMuted(false)

    expect(call.setLocalAudio.mock.calls).toEqual([[false], [true]])
  })

  it('rejects before start and after stop, like every other control', async () => {
    const transport = createDailyTransport(fakeDaily().library)
    expect((await rejection(transport.setMicMuted(true))).code).toBe('transport_error')

    const started = await startedDaily()
    await started.transport.stop()

    expect((await rejection(started.transport.setMicMuted(true))).code).toBe('transport_error')
  })
})

describe('verbatim speech and interruption', () => {
  it('say sends the text as a conversation.echo for this conversation', async () => {
    const { transport, call } = await startedDaily()

    await transport.say('Hello there.')

    expect(call.sendAppMessage).toHaveBeenCalledExactlyOnceWith(
      {
        message_type: 'conversation',
        event_type: 'conversation.echo',
        conversation_id: 'c0ffee12',
        properties: { modality: 'text', text: 'Hello there.' },
      },
      '*',
    )
  })

  it('interrupt sends a conversation.interrupt for this conversation', async () => {
    const { transport, call } = await startedDaily()

    await transport.interrupt()

    expect(call.sendAppMessage).toHaveBeenCalledExactlyOnceWith(
      {
        message_type: 'conversation',
        event_type: 'conversation.interrupt',
        conversation_id: 'c0ffee12',
      },
      '*',
    )
  })

  it.each(['say', 'interrupt'] as const)('%s rejects before start', async (control) => {
    const transport = createDailyTransport(fakeDaily().library)

    const error = await rejection(control === 'say' ? transport.say('x') : transport.interrupt())

    expect(error.code).toBe('transport_error')
  })

  it('rejects, and sends nothing, when the call is not joined', async () => {
    const { transport, call } = await startedDaily({ meetingState: 'left-meeting' })

    const error = await rejection(transport.say('x'))

    expect(error.code).toBe('transport_error')
    expect(call.sendAppMessage).not.toHaveBeenCalled()
  })

  it('rejects with a stable error when the data channel throws', async () => {
    const { transport, call } = await startedDaily()
    call.sendAppMessage.mockImplementation(() => {
      throw new Error('daily.co boom')
    })

    const error = await rejection(transport.say('x'))

    expect(error.code).toBe('transport_error')
    expect(error.message).not.toMatch(/daily/i)
  })
})

describe('stop', () => {
  it('leaves and destroys the call and clears the mount', async () => {
    const { transport, call, mount, fire } = await startedDaily()
    fire('track-started', { participant: { local: false }, track: {} })

    await transport.stop()

    expect(call.leave).toHaveBeenCalledTimes(1)
    expect(call.destroy).toHaveBeenCalledTimes(1)
    expect(mount.srcObject).toBeNull()
  })

  it('resolves even when leaving the call fails', async () => {
    const { transport, call } = await startedDaily()
    call.leave.mockRejectedValue(new Error('network error'))

    await expect(transport.stop()).resolves.toBeUndefined()
  })

  it('is idempotent', async () => {
    const { transport, call } = await startedDaily()

    await transport.stop()
    await transport.stop()

    expect(call.leave).toHaveBeenCalledTimes(1)
  })

  it('reports nothing that arrives after it: no lines, no tracks', async () => {
    const { transport, utter, fire, handlers, mount } = await startedDaily()
    await transport.stop()

    utter('replica', 'Too late.', { inference_id: 'late' })
    fire('track-started', { participant: { local: false }, track: {} })

    expect(handlers.transcript).not.toHaveBeenCalled()
    expect(mount.srcObject == null).toBe(true)
  })

  it('resolves before start', async () => {
    await expect(createDailyTransport(fakeDaily().library).stop()).resolves.toBeUndefined()
  })
})
