import type { Api } from './api'
import { HrAvatarError } from './errors'
import { releaseMedia } from './media'
import { watchCompletion } from './completion'
import { Outbox } from './outbox'
import type { Transport, TransportEvents, TransportStartOptions } from './transports/types'

export type ConversationState = 'connecting' | 'live' | 'ended' | 'error'

export type EndedReason = 'user' | 'server' | 'provider' | 'completion_phrase' | 'error'

export interface TranscriptEntry {
  role: 'user' | 'avatar'
  text: string
  /** False for an interim fragment of a line still being spoken. */
  final: boolean
  occurredAt: string
}

interface EventMap {
  state: ConversationState
  transcript: TranscriptEntry
  error: HrAvatarError
  ended: { reason: EndedReason }
}

type Handler<K extends keyof EventMap> = (payload: EventMap[K]) => void

export interface ConversationDeps {
  id: string
  api: Api
  transport: Transport
  /** False suppresses utterance forwarding only; lifecycle events are always sent. */
  forwardTranscript: boolean
  completionPhrases?: string[]
  start: TransportStartOptions
}

/** How the service says a conversation ended on its own; any other reason is the provider's. */
const SERVER_ENDED = new Set(['api', 'max_duration', 'expired'])

/** A joined conversation. Built by `connect`; the host only receives it. */
export class Conversation {
  readonly id: string
  #state: ConversationState = 'connecting'
  #liveSent = false
  #ending: Promise<void> | null = null
  #muted = false
  #speaking = false
  /** When the avatar last stopped speaking; null while it has never spoken. */
  #silentSince: number | null = null
  readonly #silenceWaiters = new Set<{ ms: number; settle: () => void; timer?: ReturnType<typeof setTimeout> }>()
  readonly #api: Api
  readonly #transport: Transport
  readonly #video: HTMLVideoElement
  readonly #outbox: Outbox
  readonly #forwardTranscript: boolean
  readonly #handlers: { [K in keyof EventMap]: Set<Handler<K>> } = {
    state: new Set(),
    transcript: new Set(),
    error: new Set(),
    ended: new Set(),
  }

  readonly #events: TransportEvents = {
    ready: () => this.#ready(),
    transcript: (line) => this.#heard(line),
    speaking: (speaking) => this.#avatarSpeaking(speaking),
    closed: () => void this.#finish('remote'),
    failed: (error) => {
      this.#emit('error', error)
      void this.#finish('error')
    },
  }

  readonly #watchCompletion: ReturnType<typeof watchCompletion>

  private constructor(deps: ConversationDeps) {
    this.id = deps.id
    this.#api = deps.api
    this.#transport = deps.transport
    this.#video = deps.start.mount
    this.#forwardTranscript = deps.forwardTranscript
    this.#watchCompletion = watchCompletion({
      phrases: deps.completionPhrases,
      whenAvatarSilent: (ms) => this.whenAvatarSilent(ms),
      onComplete: () => void this.#finish('completion_phrase'),
    })
    this.#outbox = new Outbox({
      send: (events, options) => deps.api.postEvents(deps.id, events, options),
      onError: (error) => this.#emit('error', error),
    })
  }

  /** Starts the transport; on failure the provider session is released and the error rethrown. */
  static async open(deps: ConversationDeps): Promise<Conversation> {
    const conversation = new Conversation(deps)
    try {
      await deps.transport.start(deps.start, conversation.#events)
    } catch (error) {
      await conversation.end()
      throw error
    }
    return conversation
  }

  get state(): ConversationState {
    return this.#state
  }

  /**
   * Subscribes to `state`, `transcript`, `error` or `ended` and returns the unsubscribe function.
   * A `state` handler is called at once with the current state: `connect` resolves while the
   * conversation is still `connecting`, so this is how a host sees the whole sequence.
   */
  on<K extends keyof EventMap>(event: K, handler: Handler<K>): () => void {
    const handlers = this.#handlers[event] as Set<Handler<K>>
    handlers.add(handler)
    if (event === 'state') this.#call(handler as Handler<'state'>, this.#state)
    return () => void handlers.delete(handler)
  }

  end(): Promise<void> {
    return this.#finish('user')
  }

  async setMicMuted(muted: boolean): Promise<void> {
    this.#assertActive()
    await this.#transport.setMicMuted(muted)
    this.#muted = muted
  }

  /** Flips the microphone and returns the new muted state at once; a transport failure is emitted as `error`. */
  toggleMic(): boolean {
    this.#assertActive()
    this.#muted = !this.#muted
    this.#transport.setMicMuted(this.#muted).catch((cause: unknown) => this.#emit('error', asError(cause)))
    return this.#muted
  }

  /** The avatar speaks `text` verbatim. */
  async say(text: string): Promise<void> {
    this.#assertActive()
    await this.#transport.say(text)
  }

  /** Stops the avatar's current speech. */
  async interrupt(): Promise<void> {
    this.#assertActive()
    await this.#transport.interrupt()
  }

  /** Gives the avatar extra context mid-conversation. Rejects `unsupported_operation` where the provider cannot. */
  async sendContext(payload: string | object): Promise<void> {
    this.#assertActive()
    if (!this.#transport.sendContext) {
      throw new HrAvatarError({
        code: 'unsupported_operation',
        message: 'This conversation cannot take extra context',
      })
    }
    await this.#transport.sendContext(typeof payload === 'string' ? payload : JSON.stringify(payload))
  }

  /**
   * Resolves once the avatar has produced no speech for `ms` (at once if it already has been silent
   * that long, or never spoke), and when the conversation ends, so it never hangs.
   */
  whenAvatarSilent(ms: number): Promise<void> {
    if (this.#ending) return Promise.resolve()
    return new Promise((resolve) => {
      const waiter = {
        ms,
        timer: undefined as ReturnType<typeof setTimeout> | undefined,
        settle: () => {
          clearTimeout(waiter.timer)
          this.#silenceWaiters.delete(waiter)
          resolve()
        },
      }
      this.#silenceWaiters.add(waiter)
      this.#armSilence(waiter)
    })
  }

  #assertActive(): void {
    if (this.#ending) {
      throw new HrAvatarError({ code: 'conversation_ended', message: 'The conversation has ended' })
    }
  }

  #avatarSpeaking(speaking: boolean): void {
    this.#speaking = speaking
    if (!speaking) this.#silentSince = Date.now()
    for (const waiter of this.#silenceWaiters) this.#armSilence(waiter)
  }

  /** (Re)starts a waiter's countdown from the last time the avatar stopped speaking. */
  #armSilence(waiter: { ms: number; settle: () => void; timer?: ReturnType<typeof setTimeout> }): void {
    clearTimeout(waiter.timer)
    if (this.#speaking) return
    const remaining =
      this.#silentSince === null ? 0 : Math.max(0, waiter.ms - (Date.now() - this.#silentSince))
    if (remaining === 0) waiter.settle()
    else waiter.timer = setTimeout(waiter.settle, remaining)
  }

  /** `remote` is the provider closing the connection: the reason is settled by asking the service. */
  #finish(reason: EndedReason | 'remote'): Promise<void> {
    this.#ending ??= this.#teardown(reason)
    return this.#ending
  }

  #emit<K extends keyof EventMap>(event: K, payload: EventMap[K]): void {
    for (const handler of [...this.#handlers[event]] as Handler<K>[]) this.#call(handler, payload)
  }

  /** A throwing host handler must not break the SDK or the other handlers. */
  #call<P>(handler: (payload: P) => void, payload: P): void {
    try {
      handler(payload)
    } catch (error) {
      reportError(error)
    }
  }

  #setState(state: ConversationState): void {
    if (this.#state === state) return
    this.#state = state
    this.#emit('state', state)
  }

  #ready(): void {
    if (this.#state !== 'connecting') return
    this.#setState('live')
    if (!this.#liveSent) {
      this.#liveSent = true
      this.#outbox.enqueue({ type: 'lifecycle', name: 'live' })
    }
  }

  #heard(line: { role: 'user' | 'avatar'; text: string; final: boolean }): void {
    const occurredAt = new Date().toISOString()
    const entry = { ...line, occurredAt }
    this.#emit('transcript', entry)
    this.#watchCompletion(entry)
    if (line.final && this.#forwardTranscript) {
      this.#outbox.enqueue({
        type: 'utterance',
        role: line.role,
        text: line.text,
        occurred_at: occurredAt,
      })
    }
  }

  async #teardown(reason: EndedReason | 'remote'): Promise<void> {
    await this.#outbox.flush()
    const resource = await this.#api.end(this.id).catch((cause: unknown) => {
      this.#emit('error', asError(cause))
    })
    await this.#transport.stop().catch(() => undefined)
    releaseMedia(this.#video)
    this.#outbox.dispose()
    for (const waiter of [...this.#silenceWaiters]) waiter.settle()

    this.#setState(reason === 'error' ? 'error' : 'ended')
    this.#emit('ended', {
      reason:
        reason === 'remote'
          ? resource && SERVER_ENDED.has(resource.ended_reason ?? '')
            ? 'server'
            : 'provider'
          : reason,
    })
  }
}

function asError(cause: unknown): HrAvatarError {
  return cause instanceof HrAvatarError
    ? cause
    : new HrAvatarError({ code: 'transport_error', message: 'The connection to the avatar failed' })
}
