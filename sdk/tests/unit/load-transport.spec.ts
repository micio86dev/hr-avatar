/**
 * `loadTransport` is the single switch on the transport name. It imports a provider library only
 * when that transport is chosen, maps a missing library to `provider_library_missing`, and lets the
 * host replace a whole transport (`transports`) or only the library import (`loaders`).
 */
import { describe, expect, it, vi } from 'vitest'
import { HrAvatarError } from '../../src/errors'
import { loadTransport } from '../../src/transports'
import type { Transport } from '../../src/transports/types'

// The real libraries touch browser APIs jsdom lacks; the specifiers are what is under test.
vi.mock('@daily-co/daily-js', () => ({ default: {} }))
vi.mock('@heygen/liveavatar-web-sdk', () => ({ LiveAvatarSession: class {} }))

const fakeTransport = (): Transport => ({
  start: vi.fn().mockResolvedValue(undefined),
  stop: vi.fn().mockResolvedValue(undefined),
  setMicMuted: vi.fn().mockResolvedValue(undefined),
  say: vi.fn().mockResolvedValue(undefined),
  interrupt: vi.fn().mockResolvedValue(undefined),
})

const loaders = () => ({
  daily: vi.fn().mockResolvedValue({}),
  liveavatar: vi.fn().mockResolvedValue({}),
})

async function rejection(promise: Promise<unknown>): Promise<HrAvatarError> {
  const error = await promise.then(
    () => undefined,
    (e: unknown) => e,
  )
  expect(error).toBeInstanceOf(HrAvatarError)
  return error as HrAvatarError
}

describe('overrides', () => {
  it('uses a replacement transport factory without importing the library', async () => {
    const custom = fakeTransport()
    const factory = vi.fn(() => custom)
    const spies = loaders()

    const transport = await loadTransport('daily', {
      transports: { daily: factory },
      loaders: spies,
    })

    expect(transport).toBe(custom)
    expect(factory).toHaveBeenCalledTimes(1)
    expect(spies.daily).not.toHaveBeenCalled()
    expect(spies.liveavatar).not.toHaveBeenCalled()
  })

  it('accepts an asynchronous factory', async () => {
    const custom = fakeTransport()

    await expect(
      loadTransport('liveavatar', { transports: { liveavatar: async () => custom } }),
    ).resolves.toBe(custom)
  })

  it('only overrides the transport it names', async () => {
    const spies = loaders()

    await loadTransport('liveavatar', {
      transports: { daily: () => fakeTransport() },
      loaders: spies,
    }).catch(() => undefined)

    expect(spies.liveavatar).toHaveBeenCalledTimes(1)
  })
})

describe('provider libraries', () => {
  it('builds the mock in without importing any library', async () => {
    const spies = loaders()

    const transport = await loadTransport('mock', { loaders: spies })

    expect(transport).toHaveProperty('commands')
    expect(spies.daily).not.toHaveBeenCalled()
    expect(spies.liveavatar).not.toHaveBeenCalled()
  })

  it('imports only the library of the chosen transport', async () => {
    const spies = loaders()

    await loadTransport('daily', { loaders: spies }).catch(() => undefined)

    expect(spies.daily).toHaveBeenCalledTimes(1)
    expect(spies.liveavatar).not.toHaveBeenCalled()
  })

  it.each([
    ['daily', '@daily-co/daily-js'],
    ['liveavatar', '@heygen/liveavatar-web-sdk'],
  ])('rejects with provider_library_missing naming the package for %s', async (name, pkg) => {
    const error = await rejection(
      loadTransport(name, {
        loaders: { [name]: () => Promise.reject(new Error('Cannot find module')) },
      }),
    )

    expect(error.code).toBe('provider_library_missing')
    expect(error.message).toContain(pkg)
    expect(error.recoverable).toBe(false)
  })

  it.each([
    ['daily', 'function'],
    ['liveavatar', 'undefined'],
  ])('imports the installed library for %s by default and builds its transport', async (name, sendContext) => {
    // The default loader resolves the (mocked) package; nothing is joined until `start`.
    const transport = await loadTransport(name)

    expect(transport.start).toBeTypeOf('function')
    expect(typeof transport.sendContext).toBe(sendContext)
  })
})

describe('unknown transports', () => {
  it('rejects a name the SDK cannot drive without importing anything', async () => {
    const spies = loaders()

    const error = await rejection(loadTransport('carrier-pigeon', { loaders: spies }))

    expect(error.code).toBe('unsupported_transport')
    expect(error.message).toContain('carrier-pigeon')
    expect(spies.daily).not.toHaveBeenCalled()
    expect(spies.liveavatar).not.toHaveBeenCalled()
  })

  it.each(['constructor', 'toString', '__proto__'])(
    'never resolves the inherited property %s as a transport',
    async (name) => {
      const error = await rejection(loadTransport(name, { transports: {}, loaders: {} }))

      expect(error.code).toBe('unsupported_transport')
    },
  )
})
