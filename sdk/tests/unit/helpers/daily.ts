import { vi } from 'vitest'
import { createDailyTransport } from '../../../src/transports/daily'
import type { TransportEvents } from '../../../src/transports/types'

type Handler = (data: unknown) => void

export const CONVERSATION_URL = 'https://provider.example.test/c0ffee12'

/** A stand-in for the slice of the Daily call object the transport uses. No WebRTC is involved. */
export function fakeDaily(meetingState = 'joined-meeting') {
  const handlers = new Map<string, Handler[]>()
  const fire = (event: string, data: unknown = {}) =>
    (handlers.get(event) ?? []).forEach((cb) => cb(data))

  const call = {
    on: vi.fn((event: string, cb: Handler) => {
      handlers.set(event, [...(handlers.get(event) ?? []), cb])
    }),
    join: vi.fn().mockResolvedValue(undefined),
    // Leaving on purpose makes Daily report `left-meeting` too.
    leave: vi.fn(async () => fire('left-meeting')),
    destroy: vi.fn().mockResolvedValue(undefined),
    setLocalAudio: vi.fn(),
    meetingState: vi.fn(() => meetingState),
    sendAppMessage: vi.fn(),
  }
  const library = { default: { createCallObject: vi.fn(() => call) } }

  /** A data-channel message as the provider sends it. */
  const message = (data: unknown) => fire('app-message', { data })
  const utter = (role: string, speech: string, extra: Record<string, unknown> = {}) =>
    message({
      message_type: 'conversation',
      event_type: 'conversation.utterance',
      properties: { role, speech },
      ...extra,
    })
  const started = () => message({ event_type: 'conversation.replica.started_speaking' })
  const stopped = () => message({ event_type: 'conversation.replica.stopped_speaking' })

  return { call, library, fire, message, utter, started, stopped }
}

export function transportEvents() {
  const handlers = {
    ready: vi.fn(),
    transcript: vi.fn(),
    speaking: vi.fn(),
    closed: vi.fn(),
    failed: vi.fn(),
  } satisfies TransportEvents
  /** Transcript lines as `role:text`, in the order they were reported. */
  const lines = () =>
    (handlers.transcript.mock.calls as [{ role: string; text: string }][]).map(
      ([line]) => `${line.role}:${line.text}`,
    )
  return { handlers, lines }
}

/** A started Daily transport over the fake call object. */
export async function startedDaily(options: { audioDeviceId?: string; meetingState?: string } = {}) {
  const daily = fakeDaily(options.meetingState)
  const events = transportEvents()
  const mount = document.createElement('video')
  const transport = createDailyTransport(daily.library)
  await transport.start(
    {
      join: { provider: 'tavus', transport: 'daily', conversation_url: CONVERSATION_URL },
      mount,
      ...(options.audioDeviceId ? { audioDeviceId: options.audioDeviceId } : {}),
    },
    events.handlers,
  )
  return { transport, mount, ...daily, ...events }
}
