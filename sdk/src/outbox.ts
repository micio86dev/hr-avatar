import type { ClientEvent } from './api'
import { HrAvatarError } from './errors'

/** An event as captured: the outbox adds the idempotency key and, if missing, the time. */
export type Capture = ClientEvent extends infer E
  ? E extends ClientEvent
    ? Omit<E, 'idempotency_key' | 'occurred_at'> & { occurred_at?: string }
    : never
  : never

export interface OutboxOptions {
  /** Posts one batch. Rejects with an `HrAvatarError`; `recoverable` decides whether it is retried. */
  send: (events: ClientEvent[], options: { keepalive: boolean }) => Promise<void>
  /** Called when forwarding stops or a batch is dropped. Never ends the conversation. */
  onError: (error: HrAvatarError) => void
  /** Where `pagehide` is heard. Defaults to `window` when there is one. */
  target?: EventTarget
}

const BATCH_TRIGGER = 20
const FLUSH_AFTER_MS = 2000
/** The service accepts 1-100 events per request. */
const MAX_BATCH = 100
const BACKOFF_BASE_MS = 1000
const BACKOFF_CAP_MS = 30_000

interface Attempt {
  /** The last flush of the conversation (end or page hide): one try, no retry timer. */
  final?: boolean
  keepalive?: boolean
}

/**
 * Transcript events not delivered yet, kept in capture order and held in memory only: a line is
 * personal data, so nothing is written to a browser store. A line still queued when the page goes
 * away without `pagehide` firing is lost; that is a known limit.
 */
export class Outbox {
  private queue: ClientEvent[] = []
  private flushTimer: ReturnType<typeof setTimeout> | null = null
  private retryTimer: ReturnType<typeof setTimeout> | null = null
  private failures = 0
  private stopped = false
  /** Serialises flushes: two runs over the same head event would deliver it twice. */
  private chain: Promise<void> = Promise.resolve()
  private readonly target: EventTarget | undefined
  private readonly onPageHide = () => {
    void this.run({ final: true, keepalive: true })
  }

  constructor(private readonly options: OutboxOptions) {
    this.target = options.target ?? (typeof window === 'undefined' ? undefined : window)
    this.target?.addEventListener('pagehide', this.onPageHide)
  }

  get size(): number {
    return this.queue.length
  }

  enqueue(capture: Capture): void {
    if (this.stopped) return
    this.queue.push({
      ...capture,
      idempotency_key: crypto.randomUUID(),
      occurred_at: capture.occurred_at ?? new Date().toISOString(),
    } as ClientEvent)

    if (this.queue.length >= BATCH_TRIGGER) {
      void this.run({})
    } else {
      this.flushTimer ??= setTimeout(() => void this.run({}), FLUSH_AFTER_MS)
    }
  }

  /** Last attempt before the conversation goes away. Always resolves. */
  flush(): Promise<void> {
    return this.run({ final: true })
  }

  dispose(): void {
    this.stopped = true
    this.queue = []
    this.clearTimers()
    this.target?.removeEventListener('pagehide', this.onPageHide)
  }

  private run(attempt: Attempt): Promise<void> {
    this.chain = this.chain.then(() => this.drain(attempt))
    return this.chain
  }

  private clearTimers(): void {
    if (this.flushTimer !== null) clearTimeout(this.flushTimer)
    if (this.retryTimer !== null) clearTimeout(this.retryTimer)
    this.flushTimer = null
    this.retryTimer = null
  }

  /** Never rejects: failures are routed to `onError` or to the retry timer. */
  private async drain(attempt: Attempt): Promise<void> {
    if (this.flushTimer !== null) {
      clearTimeout(this.flushTimer)
      this.flushTimer = null
    }
    // A retry is waiting out its backoff; only the last flush may go around it.
    if (this.retryTimer !== null) {
      if (!attempt.final) return
      clearTimeout(this.retryTimer)
      this.retryTimer = null
    }

    while (!this.stopped && this.queue.length > 0) {
      const batch = this.queue.slice(0, MAX_BATCH)
      try {
        await this.options.send(batch, { keepalive: attempt.keepalive ?? false })
      } catch (cause) {
        const error = toError(cause)
        if (isTerminal(error)) {
          this.dispose()
          this.options.onError(error)
          return
        }
        if (error.recoverable) {
          if (!attempt.final) this.scheduleRetry()
          return
        }
        // The service will never accept this batch; holding it would block everything behind it.
        this.queue.splice(0, batch.length)
        this.options.onError(error)
        continue
      }
      this.queue.splice(0, batch.length)
      this.failures = 0
    }
  }

  private scheduleRetry(): void {
    const delay = Math.min(BACKOFF_BASE_MS * 2 ** this.failures, BACKOFF_CAP_MS)
    this.failures += 1
    this.retryTimer = setTimeout(() => {
      this.retryTimer = null
      void this.run({})
    }, delay)
  }
}

/** The conversation is over or the credential is gone: nothing more can be delivered. */
function isTerminal(error: HrAvatarError): boolean {
  return error.status === 401 || (error.status === 409 && error.code === 'conversation_closed')
}

function toError(cause: unknown): HrAvatarError {
  return cause instanceof HrAvatarError
    ? cause
    : new HrAvatarError({ code: 'forwarding_failed', message: 'Transcript forwarding failed' })
}
