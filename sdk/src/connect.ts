import { createApi } from './api'
import { Conversation } from './conversation'
import { HrAvatarError } from './errors'
import { resolveMount } from './media'
import { loadTransport, type LoadOptions } from './transports'

export interface ConnectOptions extends LoadOptions {
  baseUrl: string
  publishableKey: string
  clientSecret: string
  /** A `<video>` is used as-is; any other element gets a `<video>` created inside it. */
  mount: HTMLElement
  audioDeviceId?: string
  completionPhrases?: string[]
  /** Forward final utterances to the service. Default true. */
  forwardTranscript?: boolean
}

const invalidSecret = () =>
  new HrAvatarError({
    code: 'invalid_client_secret',
    message: 'The client secret is not a well-formed token',
  })

/** The conversation id is the `sub` claim. It is decoded, not verified: the service verifies. */
function conversationIdFrom(clientSecret: string): string {
  const parts = clientSecret.split('.')
  if (parts.length !== 3) throw invalidSecret()
  try {
    const base64 = (parts[1] as string).replace(/-/g, '+').replace(/_/g, '/')
    const bytes = Uint8Array.from(atob(base64), (char) => char.charCodeAt(0))
    const claims: unknown = JSON.parse(new TextDecoder().decode(bytes))
    const sub = (claims as { sub?: unknown } | null)?.sub
    if (typeof sub === 'string' && sub !== '') return sub
  } catch {
    // Falls through to the single error below.
  }
  throw invalidSecret()
}

export async function connect(options: ConnectOptions): Promise<Conversation> {
  const id = conversationIdFrom(options.clientSecret)
  const api = createApi({
    baseUrl: options.baseUrl,
    publishableKey: options.publishableKey,
    clientSecret: options.clientSecret,
  })
  const join = await api.connect(id)

  // From here the service has moved the conversation to connecting and may hold a provider
  // session, so every failure releases it. `Conversation.open` does that for a failed start.
  let transport: Awaited<ReturnType<typeof loadTransport>>
  let mount: HTMLVideoElement
  try {
    transport = await loadTransport(join.transport, options)
    mount = resolveMount(options.mount)
  } catch (error) {
    await api.end(id).catch(() => undefined)
    throw error
  }

  return Conversation.open({
    id,
    api,
    transport,
    forwardTranscript: options.forwardTranscript ?? true,
    ...(options.completionPhrases ? { completionPhrases: options.completionPhrases } : {}),
    start: {
      join,
      mount,
      ...(options.audioDeviceId ? { audioDeviceId: options.audioDeviceId } : {}),
    },
  })
}
