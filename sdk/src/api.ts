import { HrAvatarError } from './errors'

/** What `connect` returns: client-safe material the transport needs to join the provider. */
export interface JoinMaterial {
  provider: string
  /** Opaque here: the transport loader owns the set of names it can drive. */
  transport: string
  conversation_url?: string | null
  session_token?: string | null
  expires_at?: string
}

/** The conversation resource members the SDK reads. */
export interface ConversationResource {
  id: string
  status: string
  ended_reason: string | null
}

export type ClientEvent =
  | {
      idempotency_key: string
      type: 'utterance'
      occurred_at: string
      role: 'user' | 'avatar'
      text: string
    }
  | {
      idempotency_key: string
      type: 'lifecycle'
      occurred_at: string
      name: 'live' | 'error'
      data?: Record<string, unknown>
    }

export interface ApiConfig {
  baseUrl: string
  publishableKey: string
  clientSecret: string
}

interface Problem {
  code?: unknown
  detail?: unknown
  title?: unknown
  request_id?: unknown
}

const isRecoverable = (status: number): boolean => status === 429 || status >= 500

/** A request that has not answered (headers and body) in this long is given up: a hung service must not hang the page. */
const REQUEST_TIMEOUT_MS = 15_000

const asString = (value: unknown): string | undefined =>
  typeof value === 'string' && value !== '' ? value : undefined

/**
 * Thin fetch wrapper for the three client routes. The publishable key and client secret live in
 * this closure only: they go to `baseUrl` in request headers and nowhere else, are never logged,
 * and are not reachable from the returned object.
 */
export function createApi(config: ApiConfig) {
  const base = config.baseUrl.replace(/\/+$/, '')
  const headers = {
    Accept: 'application/json',
    'X-HRA-Publishable-Key': config.publishableKey,
    Authorization: `Bearer ${config.clientSecret}`,
  }

  async function request<T>(path: string, body?: unknown, keepalive?: boolean): Promise<T> {
    const abort = new AbortController()
    const timer = setTimeout(() => abort.abort(), REQUEST_TIMEOUT_MS)
    try {
      return await send<T>(path, abort.signal, body, keepalive)
    } finally {
      clearTimeout(timer)
    }
  }

  async function send<T>(path: string, signal: AbortSignal, body?: unknown, keepalive?: boolean): Promise<T> {
    let response: Response
    try {
      response = await fetch(`${base}${path}`, {
        method: 'POST',
        headers: body === undefined ? headers : { ...headers, 'Content-Type': 'application/json' },
        body: body === undefined ? null : JSON.stringify(body),
        // A redirect would replay the auth headers elsewhere; ambient cookies are never wanted.
        redirect: 'error',
        credentials: 'omit',
        signal,
        ...(keepalive ? { keepalive: true } : {}),
      })
    } catch {
      // The original message is dropped on purpose: it is not ours to forward.
      throw new HrAvatarError({
        code: 'network_error',
        message: 'Network request failed',
        recoverable: true,
      })
    }

    const request_id = response.headers.get('X-Request-Id') ?? undefined
    const parsed: unknown = await response.json().catch(() => undefined)

    if (response.ok) {
      // Every client route answers with a JSON object; anything else would be returned as `T` and crash the caller later.
      if (typeof parsed !== 'object' || parsed === null) {
        throw new HrAvatarError({
          code: 'unexpected_response',
          message: 'The service answered with a body that is not a JSON object',
          status: response.status,
          ...(request_id ? { request_id } : {}),
        })
      }
      return parsed as T
    }

    const problem = (typeof parsed === 'object' && parsed !== null ? parsed : {}) as Problem
    const id = asString(problem.request_id) ?? request_id
    throw new HrAvatarError({
      code: asString(problem.code) ?? 'unexpected_response',
      message:
        asString(problem.detail) ??
        asString(problem.title) ??
        `Request failed (status ${response.status})`,
      recoverable: isRecoverable(response.status),
      status: response.status,
      ...(id ? { request_id: id } : {}),
    })
  }

  const route = (conversationId: string, action: string) =>
    `/v1/client/conversations/${encodeURIComponent(conversationId)}/${action}`

  return {
    connect: (id: string) => request<JoinMaterial>(route(id, 'connect')),
    postEvents: async (id: string, events: ClientEvent[], options?: { keepalive?: boolean }) => {
      await request<unknown>(route(id, 'events'), { events }, options?.keepalive)
    },
    end: (id: string) => request<ConversationResource>(route(id, 'end')),
  }
}

export type Api = ReturnType<typeof createApi>
