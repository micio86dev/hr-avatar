import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HrAvatarError } from '../../src'
import { open } from './helpers/open'
import { fakeService, problem } from './helpers/service'

const settle = () => vi.advanceTimersByTimeAsync(0)

async function live(
  service: Parameters<typeof fakeService>[0] = {},
  script?: Parameters<typeof open>[0],
  extra: Parameters<typeof open>[1] = {},
) {
  const fake = fakeService(service)
  const opened = await open(script, extra)
  await settle()
  const reasons: string[] = []
  opened.conversation.on('ended', ({ reason }) => reasons.push(reason))
  return { ...opened, service: fake, reasons }
}

beforeEach(() => {
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('user', () => {
  it('is the reason when the host calls end()', async () => {
    const { conversation, reasons } = await live()

    await conversation.end()

    expect(reasons).toEqual(['user'])
  })
})

describe('completion phrase', () => {
  const PHRASES = { completionPhrases: ['thank you, goodbye'] }

  it('ends the conversation 1500 ms after the avatar finished the phrase', async () => {
    const { conversation, reasons, service } = await live(
      {},
      { avatarLines: ['Thank you, goodbye!'] },
      PHRASES,
    )
    // The scripted line finished speaking at t=0 (the settle above).
    await vi.advanceTimersByTimeAsync(1499)
    expect(reasons).toEqual([])

    await vi.advanceTimersByTimeAsync(1)

    expect(reasons).toEqual(['completion_phrase'])
    expect(conversation.state).toBe('ended')
    expect(service.called('end')).toHaveLength(1)
  })

  it('ignores the user saying the phrase', async () => {
    const { conversation, mock, reasons } = await live({}, undefined, PHRASES)

    mock.emitUserLine('thank you, goodbye')
    await vi.advanceTimersByTimeAsync(10_000)

    expect(reasons).toEqual([])
    expect(conversation.state).toBe('live')
  })

  it('infers nothing from avatar speech when no phrases are configured', async () => {
    const { conversation, reasons } = await live({}, { avatarLines: ['Thank you, goodbye!'] })

    await vi.advanceTimersByTimeAsync(10_000)

    expect(reasons).toEqual([])
    expect(conversation.state).toBe('live')
  })

  it('waits for the avatar to stop talking before it counts the silence', async () => {
    const { mock, reasons } = await live({}, undefined, PHRASES)

    mock.startSpeaking()
    mock.emitAvatarLine('Thank you, goodbye!')
    await vi.advanceTimersByTimeAsync(10_000)
    expect(reasons).toEqual([])
    mock.stopSpeaking()
    await vi.advanceTimersByTimeAsync(1500)

    expect(reasons).toEqual(['completion_phrase'])
  })
})

describe('error', () => {
  it('reports a fatal transport failure, then ends with reason error', async () => {
    const { conversation, mock, reasons, service } = await live()
    const errors: HrAvatarError[] = []
    conversation.on('error', (e) => errors.push(e))
    const failure = new HrAvatarError({ code: 'transport_error', message: 'link lost' })

    mock.fail(failure)
    await settle()

    expect(errors).toEqual([failure])
    expect(reasons).toEqual(['error'])
    expect(conversation.state).toBe('error')
    expect(service.called('end')).toHaveLength(1)
  })
})

describe('remote close', () => {
  it.each([
    ['client', 'provider'],
    ['provider', 'provider'],
    ['provider_error', 'provider'],
    ['api', 'server'],
    ['max_duration', 'server'],
    ['expired', 'server'],
  ])('with the resource reporting %s ends with reason %s', async (endedReason, expected) => {
    const { mock, reasons, conversation } = await live({ endedReason })

    mock.close()
    await settle()

    expect(reasons).toEqual([expected])
    expect(conversation.state).toBe('ended')
  })

  it('flushes the outbox before it asks the service to end', async () => {
    const { mock, service } = await live()
    mock.emitUserLine('almost done')

    mock.close()
    await settle()

    const paths = service.requests.map((r) => r.path.split('/').pop())
    expect(paths.lastIndexOf('events')).toBeLessThan(paths.indexOf('end'))
  })

  it('calls the service once even if the transport reports the close again', async () => {
    const { mock, reasons, service } = await live()

    mock.close()
    mock.close()
    await settle()

    expect(service.called('end')).toHaveLength(1)
    expect(reasons).toHaveLength(1)
  })

  it('assumes the provider ended it when the service cannot be asked, and says so', async () => {
    const { conversation, mock, reasons } = await live({
      respond: (s) => (s === 'end' ? problem(503, 'service_unavailable') : undefined),
    })
    const errors: HrAvatarError[] = []
    conversation.on('error', (e) => errors.push(e))

    mock.close()
    await settle()

    expect(reasons).toEqual(['provider'])
    expect(errors[0]).toMatchObject({ code: 'service_unavailable' })
  })
})
