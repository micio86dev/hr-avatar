/** The public entry: what a host can import, and the shape of the conversation it receives. */
import { describe, expect, expectTypeOf, it } from 'vitest'
import * as sdk from '../../src'
import type {
  ConnectOptions,
  Conversation,
  ConversationState,
  EndedReason,
  HrAvatarError,
  TranscriptEntry,
} from '../../src'

describe('public entry', () => {
  it('exports exactly HrAvatar, HrAvatarError and createMockTransport as values', () => {
    expect(Object.keys(sdk).sort()).toEqual(['HrAvatar', 'HrAvatarError', 'createMockTransport'])
    expect(sdk.HrAvatar.connect).toEqual(expect.any(Function))
  })
})

describe('public types', () => {
  it('types the connect options', () => {
    expectTypeOf<ConnectOptions['mount']>().toEqualTypeOf<HTMLElement>()
    expectTypeOf<ConnectOptions['completionPhrases']>().toEqualTypeOf<string[] | undefined>()
    expectTypeOf<ConnectOptions['forwardTranscript']>().toEqualTypeOf<boolean | undefined>()
    expectTypeOf<ConnectOptions['audioDeviceId']>().toEqualTypeOf<string | undefined>()
  })

  it('names the states, ended reasons and transcript entry', () => {
    expectTypeOf<ConversationState>().toEqualTypeOf<'connecting' | 'live' | 'ended' | 'error'>()
    expectTypeOf<EndedReason>().toEqualTypeOf<
      'user' | 'server' | 'provider' | 'completion_phrase' | 'error'
    >()
    expectTypeOf<TranscriptEntry>().toEqualTypeOf<{
      role: 'user' | 'avatar'
      text: string
      final: boolean
      occurredAt: string
    }>()
  })

  it('gives the conversation the documented controls and no supports()', () => {
    expectTypeOf<Conversation['toggleMic']>().toEqualTypeOf<() => boolean>()
    expectTypeOf<Conversation['setMicMuted']>().toEqualTypeOf<(muted: boolean) => Promise<void>>()
    expectTypeOf<Conversation['say']>().toEqualTypeOf<(text: string) => Promise<void>>()
    expectTypeOf<Conversation['interrupt']>().toEqualTypeOf<() => Promise<void>>()
    expectTypeOf<Conversation['sendContext']>().toEqualTypeOf<
      (payload: string | object) => Promise<void>
    >()
    expectTypeOf<Conversation['whenAvatarSilent']>().toEqualTypeOf<(ms: number) => Promise<void>>()
    expectTypeOf<Conversation['end']>().toEqualTypeOf<() => Promise<void>>()
    expectTypeOf<Conversation>().not.toHaveProperty('supports')
  })

  it('types event payloads from the event name', () => {
    const conversation = {} as Conversation
    // Never run: it only has to type-check.
    const subscriptions = () => {
      conversation.on('state', (s) => expectTypeOf(s).toEqualTypeOf<ConversationState>())
      conversation.on('error', (e) => expectTypeOf(e).toEqualTypeOf<HrAvatarError>())
      conversation.on('transcript', (t) => expectTypeOf(t).toEqualTypeOf<TranscriptEntry>())
      conversation.on('ended', (r) => expectTypeOf(r).toEqualTypeOf<{ reason: EndedReason }>())
      return conversation.on('state', () => {})
    }

    expectTypeOf(subscriptions).returns.toEqualTypeOf<() => void>()
  })
})
