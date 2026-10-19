import { HrAvatarError } from '../errors'
import { dailyAdapter } from './daily'
import { liveavatarAdapter } from './liveavatar'
import { mockAdapter } from './mock'
import type { Adapter, Loaders, Peer, Transport, TransportFactory, TransportName } from './types'

export type { Loaders, Transport, TransportFactory, TransportName } from './types'
export type { TranscriptLine, TransportEvents, TransportStartOptions } from './types'

export interface LoadOptions {
  /** Replaces a whole transport (tests, custom hosts). The provider library is then not imported. */
  transports?: Partial<Record<TransportName, TransportFactory>>
  /** Replaces only the library import, e.g. with a CDN-backed loader in the IIFE build. */
  loaders?: Loaders
}

const ADAPTERS: Record<TransportName, Adapter> = {
  daily: dailyAdapter,
  liveavatar: liveavatarAdapter,
  mock: mockAdapter,
}

/** Own-property lookup: a name from the service must never reach `Object.prototype`. */
const own = <R extends object>(record: R | undefined, key: string): R[keyof R] | undefined =>
  record !== undefined && Object.hasOwn(record, key) ? record[key as keyof R] : undefined

/** Imports a provider library on demand; a failed import means it is not installed (or not reachable). */
async function importPeer(peer: Peer, override: Peer['load'] | undefined): Promise<unknown> {
  try {
    return await (override ?? peer.load)()
  } catch {
    throw new HrAvatarError({
      code: 'provider_library_missing',
      message: `This conversation needs the optional package "${peer.pkg}". Install it, or pass a loader for it.`,
    })
  }
}

/** Builds the transport the service chose (`JoinMaterial.transport`) by looking its adapter up by name. */
export async function loadTransport(name: string, options: LoadOptions = {}): Promise<Transport> {
  const factory = own(options.transports, name)
  if (factory) return factory()

  const adapter = own(ADAPTERS, name)
  // Until the provider transports land (slices 37 and 38) their adapters only carry the peer, so
  // the library is still resolved and a missing package is reported precisely.
  const library = adapter?.peer
    ? await importPeer(adapter.peer, own(options.loaders, name))
    : undefined
  if (adapter?.create) return adapter.create(library)

  throw new HrAvatarError({
    code: 'unsupported_transport',
    message: `Unsupported transport "${name}"`,
  })
}
