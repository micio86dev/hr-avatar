import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMockTransport } from '../../src/mock'
import { HrAvatarError } from '../../src/errors'
import { loadTransport } from '../../src/transports'
import type { TransportEvents } from '../../src/transports/types'

const join = { provider: 'mock', transport: 'mock' }

function events() {
  const log: string[] = []
  const handlers = {
    ready: vi.fn(() => void log.push('ready')),
    transcript: vi.fn((line: { role: string; text: string; final: boolean }) =>
      void log.push(`${line.role}:${line.final ? 'final' : 'interim'}:${line.text}`),
    ),
    speaking: vi.fn((on: boolean) => void log.push(on ? 'speaking' : 'silent')),
    closed: vi.fn(() => void log.push('closed')),
    failed: vi.fn(),
  } satisfies TransportEvents
  return { handlers, log }
}

async function started(script?: { avatarLines?: string[] }) {
  const mock = createMockTransport(script ? { script } : undefined)
  const { handlers, log } = events()
  const mount = document.createElement('video')
  await mock.start({ join, mount }, handlers)
  return { mock, handlers, log, mount }
}

beforeEach(() => {
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('joining', () => {
  it('reports ready as it connects', async () => {
    const { handlers } = await started()

    expect(handlers.ready).toHaveBeenCalledTimes(1)
  })

  it('performs no network or media I/O', async () => {
    const fetchSpy = vi.fn()
    vi.stubGlobal('fetch', fetchSpy)
    const getUserMedia = vi.fn()
    Object.defineProperty(navigator, 'mediaDevices', {
      value: { getUserMedia },
      configurable: true,
    })

    const { mount } = await started({ avatarLines: ['Hello'] })
    await vi.advanceTimersByTimeAsync(10_000)

    expect(fetchSpy).not.toHaveBeenCalled()
    expect(getUserMedia).not.toHaveBeenCalled()
    expect(mount.srcObject ?? null).toBeNull()
    expect(mount.getAttributeNames()).toEqual([])
    expect(mount.childElementCount).toBe(0)
    expect(Object.keys(window).filter((key) => key.startsWith('__mock'))).toEqual([])
  })
})

describe('scripted avatar lines', () => {
  it('emits each line as a final avatar transcript once live, in order, after start resolves', async () => {
    const { handlers, log } = await started({ avatarLines: ['Hello', 'How are you?'] })
    expect(handlers.transcript).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(0)

    expect(log).toEqual([
      'ready',
      'speaking',
      'avatar:final:Hello',
      'silent',
      'speaking',
      'avatar:final:How are you?',
      'silent',
    ])
  })

  it('emits nothing without a script', async () => {
    const { handlers } = await started()

    await vi.advanceTimersByTimeAsync(10_000)

    expect(handlers.transcript).not.toHaveBeenCalled()
    expect(handlers.speaking).not.toHaveBeenCalled()
  })

  it('does not emit lines after stop()', async () => {
    const { mock, handlers } = await started({ avatarLines: ['Hello'] })

    await mock.stop()
    await vi.advanceTimersByTimeAsync(10_000)

    expect(handlers.transcript).not.toHaveBeenCalled()
  })
})

describe('scripting after connect', () => {
  it('emits final and interim lines for either side', async () => {
    const { mock, log } = await started()

    mock.emitAvatarLine('Welcome')
    mock.emitUserLine('Hi there')
    mock.emitInterim('user', 'Hi th')

    expect(log).toEqual([
      'ready',
      'avatar:final:Welcome',
      'user:final:Hi there',
      'user:interim:Hi th',
    ])
  })

  it('simulates avatar speech start and stop', async () => {
    const { mock, handlers } = await started()

    mock.startSpeaking()
    mock.stopSpeaking()

    expect(handlers.speaking.mock.calls).toEqual([[true], [false]])
  })

  it('simulates a transport close', async () => {
    const { mock, handlers } = await started()

    mock.close()

    expect(handlers.closed).toHaveBeenCalledTimes(1)
  })

  it('simulates a fatal failure', async () => {
    const { mock, handlers } = await started()
    const error = new HrAvatarError({ code: 'transport_error', message: 'boom' })

    mock.fail(error)

    expect(handlers.failed).toHaveBeenCalledWith(error)
  })

  it('emits nothing once stopped', async () => {
    const { mock, handlers } = await started()

    await mock.stop()
    mock.emitAvatarLine('late')
    mock.startSpeaking()
    mock.close()

    expect(handlers.transcript).not.toHaveBeenCalled()
    expect(handlers.speaking).not.toHaveBeenCalled()
    expect(handlers.closed).not.toHaveBeenCalled()
  })

  it('refuses to be scripted before it has started', () => {
    const mock = createMockTransport()

    expect(() => mock.emitAvatarLine('too early')).toThrow(/not started/i)
  })
})

describe('controls', () => {
  it('supports every control and records what it received', async () => {
    const { mock } = await started()

    await mock.setMicMuted(true)
    await mock.say('hello')
    await mock.interrupt()
    await mock.sendContext?.('extra context')

    expect(mock.commands).toEqual([
      { type: 'mic', muted: true },
      { type: 'say', text: 'hello' },
      { type: 'interrupt' },
      { type: 'context', payload: 'extra context' },
    ])
  })

  it('records the stop and tolerates a second one', async () => {
    const { mock } = await started()

    await mock.stop()
    await mock.stop()

    expect(mock.commands).toEqual([{ type: 'stop' }])
  })
})

describe('through loadTransport', () => {
  it('is built in: loadTransport("mock") returns a working mock', async () => {
    const transport = await loadTransport('mock')
    const { handlers } = events()

    await transport.start({ join, mount: document.createElement('video') }, handlers)

    expect(handlers.ready).toHaveBeenCalledTimes(1)
  })
})
