/**
 * `sendContext` hands the avatar extra context mid-conversation. The Daily transport appends it to
 * the model's context (`conversation.append_llm_context`) and nothing else: it does not make the
 * avatar speak, so the host decides when the new context is used. The host-specific steering that
 * this was rewritten from (a fixed template, an acknowledgement and an echo filter) is gone.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HrAvatar, HrAvatarError } from '../../src'
import { CONVERSATION_URL, fakeDaily, startedDaily } from './helpers/daily'
import { SESSION_TOKEN, fakeLiveAvatar } from './helpers/liveavatar'
import { BASE_URL, CLIENT_SECRET, PUBLISHABLE_KEY, fakeService } from './helpers/service'

class FakeStream {
  addTrack() {}
}

beforeEach(() => {
  vi.stubGlobal('MediaStream', FakeStream)
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('the Daily transport', () => {
  it('appends the context to the model, and sends nothing else', async () => {
    const { transport, call } = await startedDaily()

    await transport.sendContext?.('The user changed topic.')

    expect(call.sendAppMessage).toHaveBeenCalledExactlyOnceWith(
      {
        message_type: 'conversation',
        event_type: 'conversation.append_llm_context',
        conversation_id: 'c0ffee12',
        properties: { context: 'The user changed topic.' },
      },
      '*',
    )
  })

  it('rejects when the call is not joined', async () => {
    const { transport, call } = await startedDaily({ meetingState: 'left-meeting' })

    const error = await transport.sendContext?.('x').then(
      () => undefined,
      (e: unknown) => e,
    )

    expect(error).toBeInstanceOf(HrAvatarError)
    expect((error as HrAvatarError).code).toBe('transport_error')
    expect(call.sendAppMessage).not.toHaveBeenCalled()
  })
})

describe('through a Daily conversation', () => {
  async function connectDaily() {
    const daily = fakeDaily()
    fakeService({
      respond: (suffix) =>
        suffix === 'connect'
          ? new Response(
              JSON.stringify({
                provider: 'tavus',
                transport: 'daily',
                conversation_url: CONVERSATION_URL,
              }),
              { status: 200, headers: { 'content-type': 'application/json' } },
            )
          : undefined,
    })
    const conversation = await HrAvatar.connect({
      baseUrl: BASE_URL,
      publishableKey: PUBLISHABLE_KEY,
      clientSecret: CLIENT_SECRET,
      mount: document.createElement('div'),
      loaders: { daily: async () => daily.library },
    })
    return { conversation, daily }
  }

  it('serialises an object payload as JSON text', async () => {
    const { conversation, daily } = await connectDaily()

    await conversation.sendContext({ topic: 'budget' })

    expect(daily.call.sendAppMessage).toHaveBeenCalledWith(
      expect.objectContaining({
        event_type: 'conversation.append_llm_context',
        properties: { context: '{"topic":"budget"}' },
      }),
      '*',
    )
  })

  it('builds the transport from the lazily imported library', async () => {
    const { daily } = await connectDaily()

    expect(daily.library.default.createCallObject).toHaveBeenCalledTimes(1)
    expect(daily.call.join).toHaveBeenCalledWith({ url: CONVERSATION_URL, startVideoOff: true })
  })
})

describe('through a LiveAvatar conversation', () => {
  it('rejects with unsupported_operation: that provider cannot take extra context', async () => {
    const live = fakeLiveAvatar()
    fakeService({
      respond: (suffix) =>
        suffix === 'connect'
          ? new Response(
              JSON.stringify({
                provider: 'heygen',
                transport: 'liveavatar',
                session_token: SESSION_TOKEN,
              }),
              { status: 200, headers: { 'content-type': 'application/json' } },
            )
          : undefined,
    })
    const conversation = await HrAvatar.connect({
      baseUrl: BASE_URL,
      publishableKey: PUBLISHABLE_KEY,
      clientSecret: CLIENT_SECRET,
      mount: document.createElement('div'),
      loaders: { liveavatar: async () => live.library },
    })

    const error = await conversation.sendContext({ a: 1 }).then(
      () => undefined,
      (e: unknown) => e,
    )

    expect(error).toBeInstanceOf(HrAvatarError)
    expect((error as HrAvatarError).code).toBe('unsupported_operation')
    await conversation.say('Still works.')
    expect(live.session.repeat).toHaveBeenCalledWith('Still works.')
  })
})
