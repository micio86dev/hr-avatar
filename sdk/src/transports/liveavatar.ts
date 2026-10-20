import { connectionNotOpen, providerUnavailable } from './failures'
import { createSpeechTracker } from './speech'
import type { Adapter, Transport, TransportEvents, TransportStartOptions } from './types'

interface VoiceChat {
  readonly isMuted: boolean
  mute(): Promise<void>
  unmute(): Promise<void>
}

/** The slice of the SDK's `LiveAvatarSession` this transport uses. */
interface LiveSession {
  on(event: string, handler: (data?: unknown) => void): void
  start(): Promise<void>
  stop(): Promise<void>
  attach(element: HTMLMediaElement): void
  interrupt(): void
  repeat(text: string): string
  readonly voiceChat: VoiceChat
}

/**
 * The `SessionConfig` subset this transport sets. It mirrors the SDK's own type instead of
 * importing it: the SDK is an optional peer and must never be imported at module scope.
 */
interface SessionConfig {
  voiceChat: { mode: 'CONVERSATIONAL' | 'PUSH_TO_TALK'; defaultMuted: boolean; deviceId?: string }
}

/** The part of the `@heygen/liveavatar-web-sdk` module this transport uses. */
export interface LiveAvatarLibrary {
  LiveAvatarSession: new (sessionAccessToken: string, config: SessionConfig) => LiveSession
}

/**
 * SDK event names, as string literals. They are deliberately not the SDK's `SessionEvent` and
 * `AgentEventsEnum` runtime enums, which would pull the SDK in at import time. A unit test pins
 * every value against the shipped declarations.
 */
const SDK_EVENT = {
  streamReady: 'session.stream_ready',
  disconnected: 'session.disconnected',
  userSpeakStarted: 'user.speak_started',
  avatarSpeakStarted: 'avatar.speak_started',
  avatarSpeakEnded: 'avatar.speak_ended',
  userTranscription: 'user.transcription',
  userTranscriptionChunk: 'user.transcription.chunk',
  avatarTranscription: 'avatar.transcription',
  avatarTranscriptionChunk: 'avatar.transcription.chunk',
} as const

const textOf = (data: unknown): string | undefined => {
  const text = (data as { text?: unknown } | null | undefined)?.text
  return typeof text === 'string' && text !== '' ? text : undefined
}

/**
 * The LiveAvatar transport. One transport serves one conversation: `connect` builds a new one per
 * call and `stop()` is final.
 */
export function createLiveAvatarTransport(library: LiveAvatarLibrary): Transport {
  let session: LiveSession | null = null
  let events: TransportEvents | null = null
  /** Set by `stop()`, a disconnect or a failed start: nothing after it is reported. */
  let ending = false
  let readySent = false
  const voice = createSpeechTracker((speaking) => events?.speaking(speaking))

  const ready = (): void => {
    if (readySent) return
    readySent = true
    events?.ready()
  }

  const open = (): LiveSession => {
    if (session === null) throw connectionNotOpen()
    return session
  }

  /** Runs a control on the open session; a throwing session is a refused control, not a crash. */
  function control<T>(run: (open: LiveSession) => T): T {
    const current = open()
    try {
      return run(current)
    } catch {
      throw connectionNotOpen()
    }
  }

  function wire(live: LiveSession, mount: HTMLVideoElement): void {
    const listen = (name: string, handler: (data?: unknown) => void): void =>
      live.on(name, (data) => {
        if (!ending) handler(data)
      })

    // attach() belongs on stream-ready, not right after start(): start() resolves once the room is
    // joined, which is not the instant the tracks exist.
    listen(SDK_EVENT.streamReady, () => {
      live.attach(mount)
      void mount.play?.().catch(() => undefined)
      ready()
    })

    listen(SDK_EVENT.disconnected, () => {
      ending = true
      session = null
      voice.silence()
      events?.closed()
    })

    // The user talking over the avatar cuts the avatar off, as a person would stop. The server's
    // voice detection also covers this, so a throwing interrupt() is not fatal.
    listen(SDK_EVENT.userSpeakStarted, () => {
      if (!voice.playing()) return
      voice.audio(false)
      try {
        live.interrupt()
      } catch {
        // Server-side voice detection covers barge-in.
      }
    })
    listen(SDK_EVENT.avatarSpeakStarted, () => voice.audio(true))
    listen(SDK_EVENT.avatarSpeakEnded, () => voice.audio(false))

    const hear = (name: string, role: 'user' | 'avatar', final: boolean): void =>
      listen(name, (data) => {
        const text = textOf(data)
        if (text === undefined) return
        // A final avatar line can precede its audio's start edge: speaking from now, before the
        // line is reported, so a completion phrase waits for silence.
        if (role === 'avatar' && final) voice.expectAudio()
        events?.transcript({ role, text, final })
      })
    hear(SDK_EVENT.avatarTranscription, 'avatar', true)
    hear(SDK_EVENT.avatarTranscriptionChunk, 'avatar', false)
    hear(SDK_EVENT.userTranscription, 'user', true)
    hear(SDK_EVENT.userTranscriptionChunk, 'user', false)
  }

  return {
    async start({ join, mount, audioDeviceId }: TransportStartOptions, handlers): Promise<void> {
      events = handlers
      try {
        if (!join.session_token) throw new Error('The join material has no session token')
        // The voiceChat config is what lets the session HEAR: the SDK publishes the local
        // microphone only when it is given. CONVERSATIONAL is hands-free turn-taking, unmuted from
        // the first second.
        session = new library.LiveAvatarSession(join.session_token, {
          voiceChat: {
            mode: 'CONVERSATIONAL',
            defaultMuted: false,
            ...(audioDeviceId ? { deviceId: audioDeviceId } : {}),
          },
        })
        wire(session, mount)
        await session.start()
        // Stopped, or disconnected by the provider, while the session was starting: that is not a conversation that started.
        if (ending) throw new Error('The session ended while starting')
        ready()
      } catch {
        ending = true
        const failed = session
        session = null
        await failed?.stop().catch(() => undefined)
        throw providerUnavailable()
      }
    },

    async stop(): Promise<void> {
      // Before awaiting: a disconnect can land while stop() is in flight, and it is ours.
      ending = true
      voice.cancel()
      const stopping = session
      session = null
      try {
        await stopping?.stop()
      } catch {
        // Teardown is best effort: the session is going away either way.
      }
    },

    async setMicMuted(muted: boolean): Promise<void> {
      // The live SDK state, not a local mirror: anything that mutes the track out of band would
      // desynchronise a boolean, and the control would then do the opposite of what was asked.
      const chat = open().voiceChat
      if (chat.isMuted === muted) return
      try {
        await (muted ? chat.mute() : chat.unmute())
      } catch {
        throw connectionNotOpen()
      }
    },

    async say(text: string): Promise<void> {
      control((live) => live.repeat(text))
    },

    async interrupt(): Promise<void> {
      control((live) => live.interrupt())
    },
  }
}

export const liveavatarAdapter: Adapter = {
  peer: {
    pkg: '@heygen/liveavatar-web-sdk',
    load: () => import('@heygen/liveavatar-web-sdk'),
  },
  create: (library) => createLiveAvatarTransport(library as LiveAvatarLibrary),
}
