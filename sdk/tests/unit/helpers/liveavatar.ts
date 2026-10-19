import { vi } from 'vitest'
import { createLiveAvatarTransport } from '../../../src/transports/liveavatar'
import { transportEvents } from './daily'

type Handler = (data?: unknown) => void

export const SESSION_TOKEN = 'session-token-123'

/** A stand-in for `LiveAvatarSession` with the shape of the real SDK. No WebRTC or sockets. */
export function fakeLiveAvatar() {
  const handlers = new Map<string, Handler[]>()
  const fire = (event: string, data?: unknown) => (handlers.get(event) ?? []).forEach((cb) => cb(data))

  const voiceChat = {
    isMuted: false,
    mute: vi.fn(async () => {
      voiceChat.isMuted = true
    }),
    unmute: vi.fn(async () => {
      voiceChat.isMuted = false
    }),
  }
  const session = {
    on: vi.fn((event: string, cb: Handler) => {
      handlers.set(event, [...(handlers.get(event) ?? []), cb])
    }),
    start: vi.fn().mockResolvedValue(undefined),
    stop: vi.fn().mockResolvedValue(undefined),
    attach: vi.fn(),
    interrupt: vi.fn(),
    repeat: vi.fn().mockReturnValue(''),
    voiceChat,
  }
  const LiveAvatarSession = vi.fn(function () {
    return session
  })

  return { session, voiceChat, fire, LiveAvatarSession, library: { LiveAvatarSession } }
}

/** A `<video>` the transport may attach to; jsdom has no media pipeline, so `play` is stubbed. */
export function videoMount() {
  const mount = document.createElement('video')
  mount.play = vi.fn().mockResolvedValue(undefined)
  return mount
}

export async function startedLiveAvatar(options: { audioDeviceId?: string } = {}) {
  const fake = fakeLiveAvatar()
  const events = transportEvents()
  const mount = videoMount()
  const transport = createLiveAvatarTransport(fake.library)
  await transport.start(
    {
      join: { provider: 'heygen', transport: 'liveavatar', session_token: SESSION_TOKEN },
      mount,
      ...(options.audioDeviceId ? { audioDeviceId: options.audioDeviceId } : {}),
    },
    events.handlers,
  )
  return { transport, mount, ...fake, ...events }
}
