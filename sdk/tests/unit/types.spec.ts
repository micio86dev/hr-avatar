/**
 * Type-level tests for the internal transport contract (src/transports/types.ts). The dynamic
 * import makes the suite fail until the module exists; the assertions are checked by `tsc`.
 */
import { describe, expectTypeOf, it } from 'vitest'
import type { HrAvatarError } from '../../src/errors'
import type {
  Loaders,
  Transport,
  TransportEvents,
  TransportFactory,
  TransportName,
  TranscriptLine,
} from '../../src/transports/types'

describe('transport contract', () => {
  it('lives in a module that exists', async () => {
    await import('../../src/transports/types')
  })

  it('names exactly the three transports', () => {
    expectTypeOf<TransportName>().toEqualTypeOf<'daily' | 'liveavatar' | 'mock'>()
  })

  it('reports transcript lines as role, text and a final flag', () => {
    expectTypeOf<TranscriptLine>().toEqualTypeOf<{
      role: 'user' | 'avatar'
      text: string
      final: boolean
    }>()
  })

  it('receives five events from the transport', () => {
    expectTypeOf<TransportEvents['ready']>().toEqualTypeOf<() => void>()
    expectTypeOf<TransportEvents['transcript']>().toEqualTypeOf<(line: TranscriptLine) => void>()
    expectTypeOf<TransportEvents['speaking']>().toEqualTypeOf<(speaking: boolean) => void>()
    expectTypeOf<TransportEvents['closed']>().toEqualTypeOf<() => void>()
    expectTypeOf<TransportEvents['failed']>().toEqualTypeOf<(error: HrAvatarError) => void>()
  })

  it('has required controls and an optional sendContext', () => {
    expectTypeOf<Transport['start']>().returns.toEqualTypeOf<Promise<void>>()
    expectTypeOf<Transport['stop']>().returns.toEqualTypeOf<Promise<void>>()
    expectTypeOf<Transport['setMicMuted']>().parameters.toEqualTypeOf<[muted: boolean]>()
    expectTypeOf<Transport['say']>().parameters.toEqualTypeOf<[text: string]>()
    expectTypeOf<Transport['interrupt']>().returns.toEqualTypeOf<Promise<void>>()
    expectTypeOf<Transport>().toHaveProperty('sendContext')
    expectTypeOf<Pick<Transport, 'sendContext'>>().toEqualTypeOf<{
      sendContext?: (payload: string) => Promise<void>
    }>()
  })

  it('builds a transport from a factory, sync or async', () => {
    expectTypeOf<TransportFactory>().toEqualTypeOf<() => Transport | Promise<Transport>>()
  })

  it('lets the host replace only the library import', () => {
    expectTypeOf<Loaders>().toEqualTypeOf<{
      daily?: () => Promise<unknown>
      liveavatar?: () => Promise<unknown>
    }>()
  })
})
