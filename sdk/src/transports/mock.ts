import type { HrAvatarError } from '../errors'
import type { Adapter, Transport, TransportEvents, TransportStartOptions } from './types'

export interface MockScript {
  /** Avatar lines emitted, in order, as soon as the conversation is live. */
  avatarLines?: string[]
}

/** What the mock received from the conversation's controls, for host tests to assert on. */
export type MockCommand =
  | { type: 'mic'; muted: boolean }
  | { type: 'say'; text: string }
  | { type: 'interrupt' }
  | { type: 'context'; payload: string }
  | { type: 'stop' }

/** Drives the mock from a test after `connect` resolved. */
export interface MockControls {
  readonly commands: readonly MockCommand[]
  emitAvatarLine(text: string): void
  emitUserLine(text: string): void
  emitInterim(role: 'user' | 'avatar', text: string): void
  startSpeaking(): void
  stopSpeaking(): void
  /** The provider side closes the connection. */
  close(): void
  fail(error: HrAvatarError): void
}

/**
 * A deterministic transport with no network or media I/O. It supports every control, so it is also
 * the reference for what a real transport must do.
 */
export function createMockTransport(options: { script?: MockScript } = {}): Transport & MockControls {
  const commands: MockCommand[] = []
  let events: TransportEvents | null = null
  let stopped = false
  let scriptTimer: ReturnType<typeof setTimeout> | null = null

  function live(): TransportEvents | null {
    if (events === null) throw new Error('The mock transport is not started yet')
    return stopped ? null : events
  }

  const record = async (command: MockCommand): Promise<void> => {
    commands.push(command)
  }

  const emitLine = (role: 'user' | 'avatar', text: string, final: boolean): void =>
    live()?.transcript({ role, text, final })

  return {
    commands,

    async start(_options: TransportStartOptions, handlers: TransportEvents): Promise<void> {
      events = handlers
      handlers.ready()
      // After start() resolves, so the host has had time to subscribe to the conversation.
      scriptTimer = setTimeout(() => {
        for (const text of options.script?.avatarLines ?? []) {
          live()?.speaking(true)
          emitLine('avatar', text, true)
          live()?.speaking(false)
        }
      }, 0)
    },

    async stop(): Promise<void> {
      if (stopped) return
      stopped = true
      if (scriptTimer !== null) clearTimeout(scriptTimer)
      await record({ type: 'stop' })
    },

    setMicMuted: (muted) => record({ type: 'mic', muted }),
    say: (text) => record({ type: 'say', text }),
    interrupt: () => record({ type: 'interrupt' }),
    sendContext: (payload) => record({ type: 'context', payload }),

    emitAvatarLine: (text) => emitLine('avatar', text, true),
    emitUserLine: (text) => emitLine('user', text, true),
    emitInterim: (role, text) => emitLine(role, text, false),
    startSpeaking: () => live()?.speaking(true),
    stopSpeaking: () => live()?.speaking(false),
    close: () => live()?.closed(),
    fail: (error) => live()?.failed(error),
  }
}

export const mockAdapter: Adapter = { create: () => createMockTransport() }
