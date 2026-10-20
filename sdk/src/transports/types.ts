import type { JoinMaterial } from '../api'
import type { HrAvatarError } from '../errors'

export type TransportName = 'daily' | 'liveavatar' | 'mock'

/** One thing said in the conversation, as a transport reports it. `final: false` is an interim fragment. */
export interface TranscriptLine {
  role: 'user' | 'avatar'
  text: string
  final: boolean
}

/** What a transport tells the conversation. */
export interface TransportEvents {
  /** The media is up and the avatar can be heard: the conversation is live. */
  ready: () => void
  transcript: (line: TranscriptLine) => void
  /** The avatar started (`true`) or stopped (`false`) speaking. */
  speaking: (speaking: boolean) => void
  /** The provider side closed the connection without being asked to. */
  closed: () => void
  /** A fatal transport error. */
  failed: (error: HrAvatarError) => void
}

export interface TransportStartOptions {
  join: JoinMaterial
  /** Where the avatar is rendered. Always a `<video>`: see `resolveMount`. */
  mount: HTMLVideoElement
  audioDeviceId?: string
}

/** The SDK side of the provider contract: one implementation per `JoinMaterial.transport`. */
export interface Transport {
  /** Joins the provider and resolves once connected. */
  start(options: TransportStartOptions, events: TransportEvents): Promise<void>
  /** Leaves the provider and releases what `start` created. Idempotent. */
  stop(): Promise<void>
  setMicMuted(muted: boolean): Promise<void>
  /** The avatar speaks `text` verbatim. */
  say(text: string): Promise<void>
  /** Stops the avatar's current speech. */
  interrupt(): Promise<void>
  /** Absent when the provider cannot take extra context mid-conversation. */
  sendContext?(payload: string): Promise<void>
}

export type TransportFactory = () => Transport | Promise<Transport>

/** Replaces only the dynamic `import()` of a provider library (CDN loaders, tests). */
export interface Loaders {
  daily?: () => Promise<unknown>
  liveavatar?: () => Promise<unknown>
}

/** An optional provider library: its package name and how to import it. */
export interface Peer {
  pkg: string
  load: () => Promise<unknown>
}

/**
 * What the registry knows about one transport. The adapter owns its peer package and its builder,
 * so the registry only looks an adapter up by name.
 */
export interface Adapter {
  peer?: Peer
  /** Builds the transport from the imported library (`undefined` for the built-in mock). */
  create?: (library: unknown) => Transport
}
