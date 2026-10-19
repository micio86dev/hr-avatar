import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HrAvatar, HrAvatarError } from '../../src'
import type { ConversationState } from '../../src'
import type { Transport, TransportEvents } from '../../src/transports/types'
import { open } from './helpers/open'
import {
  BASE_URL,
  CLIENT_SECRET,
  PUBLISHABLE_KEY,
  fakeService,
  problem,
} from './helpers/service'

const settle = () => vi.advanceTimersByTimeAsync(0)

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(new Date('2026-10-19T10:00:00.000Z'))
})

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('state', () => {
  it('goes connecting, live, ended, and tells a late subscriber where it is', async () => {
    fakeService()
    const { conversation } = await open()
    const states: ConversationState[] = []

    conversation.on('state', (state) => states.push(state))
    await settle()
    await conversation.end()

    expect(states).toEqual(['connecting', 'live', 'ended'])
    expect(conversation.state).toBe('ended')
  })
})

describe('subscription', () => {
  it('stops calling a handler once it is unsubscribed', async () => {
    fakeService()
    const { conversation, mock } = await open()
    await settle()
    const handler = vi.fn()

    const off = conversation.on('transcript', handler)
    mock.emitAvatarLine('one')
    off()
    mock.emitAvatarLine('two')

    expect(handler).toHaveBeenCalledTimes(1)
  })

  it('keeps delivering to other handlers when one throws, and reports the failure to the page', async () => {
    fakeService()
    const { conversation, mock } = await open()
    await settle()
    const survivor = vi.fn()
    const failure = new Error('host bug')
    conversation.on('transcript', () => {
      throw failure
    })
    conversation.on('transcript', survivor)
    // jsdom has no reportError; browsers do, and it surfaces the failure like an uncaught error.
    const reported = vi.fn()
    vi.stubGlobal('reportError', reported)

    mock.emitAvatarLine('hello')

    expect(survivor).toHaveBeenCalledTimes(1)
    expect(reported).toHaveBeenCalledWith(failure)
    expect(conversation.state).toBe('live')
  })
})

describe('transcript', () => {
  it('delivers role, text, final and the observed time', async () => {
    fakeService()
    const { conversation, mock } = await open()
    await settle()
    const heard: unknown[] = []
    conversation.on('transcript', (entry) => heard.push(entry))

    mock.emitUserLine('hi there')
    mock.emitInterim('avatar', 'Hel')

    expect(heard).toEqual([
      { role: 'user', text: 'hi there', final: true, occurredAt: '2026-10-19T10:00:00.000Z' },
      { role: 'avatar', text: 'Hel', final: false, occurredAt: '2026-10-19T10:00:00.000Z' },
    ])
  })

  it('forwards final utterances with the observed time and never the interim ones', async () => {
    const service = fakeService()
    const { conversation, mock } = await open()
    await settle()

    mock.emitInterim('user', 'hi th')
    mock.emitUserLine('hi there')
    await vi.advanceTimersByTimeAsync(2000)
    await conversation.end()

    const utterances = service.events().filter((e) => e.type === 'utterance')
    expect(utterances).toHaveLength(1)
    expect(utterances[0]).toMatchObject({
      type: 'utterance',
      role: 'user',
      text: 'hi there',
      occurred_at: '2026-10-19T10:00:00.000Z',
    })
  })

  it('sends lifecycle live exactly once, before the utterances', async () => {
    const service = fakeService()
    let events!: TransportEvents
    const stub: Transport = {
      start: async (_options, handlers) => void (events = handlers),
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

    events.ready()
    events.ready()
    events.transcript({ role: 'user', text: 'hello', final: true })
    await conversation.end()

    expect(service.events().map((e) => (e.type === 'lifecycle' ? e.name : e.type))).toEqual([
      'live',
      'utterance',
    ])
  })
})

describe('forwarding failures', () => {
  it('emits an error without ending the conversation when the service says it is closed', async () => {
    fakeService({
      respond: (s) => (s === 'events' ? problem(409, 'conversation_closed') : undefined),
    })
    const { conversation, mock } = await open()
    await settle()
    const errors: HrAvatarError[] = []
    const ended = vi.fn()
    conversation.on('error', (error) => errors.push(error))
    conversation.on('ended', ended)

    mock.emitUserLine('hello')
    await vi.advanceTimersByTimeAsync(2000)

    expect(errors).toHaveLength(1)
    expect(errors[0]).toMatchObject({ code: 'conversation_closed', status: 409 })
    expect(conversation.state).toBe('live')
    expect(ended).not.toHaveBeenCalled()
  })
})

describe('ended', () => {
  it('fires once with reason user however often the conversation is ended or closed', async () => {
    fakeService()
    const { conversation, mock } = await open()
    await settle()
    const ended = vi.fn()
    conversation.on('ended', ended)

    await conversation.end()
    await conversation.end()
    mock.close()
    await settle()

    expect(ended).toHaveBeenCalledTimes(1)
    expect(ended).toHaveBeenCalledWith({ reason: 'user' })
  })
})
