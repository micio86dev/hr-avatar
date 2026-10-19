import { connect } from './connect'

export { HrAvatarError } from './errors'
export { createMockTransport } from './transports/mock'

export const HrAvatar = { connect }

export type { HrAvatarErrorInit } from './errors'
export type { ConnectOptions } from './connect'
export type {
  Conversation,
  ConversationState,
  EndedReason,
  TranscriptEntry,
} from './conversation'
export type { Loaders, Transport, TransportFactory, TransportName } from './transports'
export type { MockCommand, MockControls, MockScript } from './transports/mock'
