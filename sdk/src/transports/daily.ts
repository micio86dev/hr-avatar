import { connectionNotOpen, providerUnavailable } from './failures'
import { createSpeechTracker } from './speech'
import type { Adapter, Transport, TransportEvents, TransportStartOptions } from './types'

type Data = Record<string, unknown>
type Handler = (data: Data | undefined) => void

/** The slice of Daily's call object this transport uses. */
interface DailyCall {
  on(event: string, handler: Handler): void
  join(options: Record<string, unknown>): Promise<unknown>
  leave(): Promise<void>
  destroy(): Promise<void>
  setLocalAudio(enabled: boolean): void
  meetingState(): string
  sendAppMessage(data: unknown, to: string): void
}

/** The part of the `@daily-co/daily-js` module this transport uses. */
export interface DailyLibrary {
  default: { createCallObject(options: Record<string, unknown>): DailyCall }
}

/** How many avatar utterance keys are remembered for duplicate suppression. */
const SEEN_AVATAR_LIMIT = 50

/** Twin window for avatar utterances that carry no `inference_id`. */
const TWIN_WINDOW_MS = 2_000

/**
 * The provider's conversation id is the last segment of its conversation url. Any other shape
 * (missing, relative, no path) is a join we cannot address messages to, so `start` reports it as
 * the provider being unavailable, like any other failure to join.
 */
function conversationIdFrom(conversationUrl: string | null | undefined): string {
  const id = new URL(conversationUrl ?? '').pathname.split('/').filter(Boolean).pop()
  if (id === undefined) throw new Error('The conversation url has no id')
  return id
}

/**
 * The Daily transport: a call object with no UI of its own. The remote tracks are rendered into
 * the host's `<video>`, so the page shows the avatar and nothing else. One transport serves one
 * conversation: `connect` builds a new one per call and `stop()` is final.
 */
export function createDailyTransport(library: DailyLibrary): Transport {
  let call: DailyCall | null = null
  let events: TransportEvents | null = null
  let video: HTMLVideoElement | null = null
  let stream: MediaStream | null = null
  let conversationId = ''
  /** Set by `stop()` or by the first unannounced end: a later `left-meeting` is not news. */
  let ending = false
  let readySent = false

  const voice = createSpeechTracker((speaking) => events?.speaking(speaking))

  /** Avatar utterances already reported (key -> time seen), oldest first. */
  const seen = new Map<string, number>()

  const ready = (): void => {
    if (readySent) return
    readySent = true
    events?.ready()
  }

  /**
   * Records an avatar utterance and reports whether it was already seen. Utterances sharing an
   * `inference_id` are the same one (no time limit). Without one, the key is (turn_idx, speech) and
   * a twin only counts inside TWIN_WINDOW_MS, so the avatar repeating a sentence later is kept.
   */
  function isDuplicate(data: Data, speech: string): boolean {
    const id = data.inference_id
    const hasId = typeof id === 'string' && id !== ''
    const key = hasId ? `i:${id}` : `s:${String(data.turn_idx ?? '')}:${speech}`
    const now = Date.now()
    const previous = seen.get(key)

    if (previous !== undefined && (hasId || now - previous <= TWIN_WINDOW_MS)) return true

    seen.delete(key)
    seen.set(key, now)
    if (seen.size > SEEN_AVATAR_LIMIT) seen.delete(seen.keys().next().value as string)
    return false
  }

  function onUtterance(data: Data): void {
    const properties = data.properties as { role?: string; speech?: string } | undefined
    const speech = properties?.speech
    if (typeof speech !== 'string' || speech === '') return

    // "pal" is the provider's legacy duplicate of "replica": both are the avatar.
    const avatar = properties?.role === 'replica' || properties?.role === 'pal'
    if (avatar) {
      if (isDuplicate(data, speech)) return
      // Speaking from now, before the line is reported: a completion phrase waits for silence, and
      // the line can arrive ahead of its audio.
      voice.expectAudio()
    }
    events?.transcript({ role: avatar ? 'avatar' : 'user', text: speech, final: true })
  }

  function onMessage(data: Data | undefined): void {
    switch (data?.event_type) {
      case 'conversation.replica.started_speaking':
        voice.audio(true)
        return
      case 'conversation.replica.stopped_speaking':
        voice.audio(false)
        return
      case 'conversation.utterance':
        onUtterance(data)
    }
  }

  function attachTrack(track: MediaStreamTrack): void {
    if (video === null) return
    stream ??= new MediaStream()
    stream.addTrack(track)
    // Reassigned on every track: audio and video arrive as separate events, and some browsers
    // ignore tracks added to a stream that is already assigned.
    video.srcObject = stream
  }

  function wire(daily: DailyCall): void {
    daily.on('joined-meeting', ready)
    daily.on('track-started', (event) => {
      if (ending) return
      const track = event?.track as MediaStreamTrack | undefined
      // A local track is the user's own microphone: rendering it plays their voice back at them.
      // The payload marks it with a `local` flag on the object that names the track's owner, which
      // is read structurally instead of by that object's key.
      const local = Object.values(event ?? {}).some(
        (value) => (value as { local?: unknown } | null)?.local === true,
      )
      if (local || track === undefined) return
      attachTrack(track)
    })
    daily.on('left-meeting', () => {
      // The provider ended the call (its ceiling, a dropped connection). Our own leave is not news.
      if (ending) return
      ending = true
      voice.cancel()
      events?.closed()
    })
    daily.on('app-message', (event) => {
      if (!ending) onMessage(event?.data as Data | undefined)
    })
  }

  /** The only outbound data-channel site. */
  function send(message: Data): void {
    if (call === null || call.meetingState() !== 'joined-meeting') throw connectionNotOpen()
    try {
      call.sendAppMessage({ message_type: 'conversation', ...message, conversation_id: conversationId }, '*')
    } catch {
      throw connectionNotOpen()
    }
  }

  return {
    async start(options: TransportStartOptions, handlers: TransportEvents): Promise<void> {
      events = handlers
      video = options.mount
      try {
        conversationId = conversationIdFrom(options.join.conversation_url)
        // No camera: the host owns any video of the user, and two consumers of one device fail.
        call = library.default.createCallObject({
          audioSource: options.audioDeviceId ?? true,
          videoSource: false,
        })
        wire(call)
        await call.join({ url: options.join.conversation_url, startVideoOff: true })
        ready()
      } catch {
        // A stable code, never the library's text: it names the vendor and its hosts.
        ending = true
        const failed = call
        call = null
        await failed?.destroy().catch(() => undefined)
        throw providerUnavailable()
      }
    },

    async stop(): Promise<void> {
      ending = true
      voice.cancel()
      const leaving = call
      call = null
      if (leaving) {
        try {
          await leaving.leave()
          await leaving.destroy()
        } catch {
          // Teardown is best effort: the call is going away either way.
        }
      }
      if (video !== null) video.srcObject = null
      stream = null
    },

    async setMicMuted(muted: boolean): Promise<void> {
      if (call === null) throw connectionNotOpen()
      try {
        // Daily takes ENABLED, so the flag is inverted.
        call.setLocalAudio(!muted)
      } catch {
        throw connectionNotOpen()
      }
    },

    async say(text: string): Promise<void> {
      send({ event_type: 'conversation.echo', properties: { modality: 'text', text } })
    },

    async interrupt(): Promise<void> {
      send({ event_type: 'conversation.interrupt' })
    },

    async sendContext(payload: string): Promise<void> {
      send({ event_type: 'conversation.append_llm_context', properties: { context: payload } })
    },
  }
}

export const dailyAdapter: Adapter = {
  peer: { pkg: '@daily-co/daily-js', load: () => import('@daily-co/daily-js') },
  create: (library) => createDailyTransport(library as DailyLibrary),
}
