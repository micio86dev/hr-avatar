/**
 * Speaking signals. The conversation derives `whenAvatarSilent` and completion phrases from them,
 * so the transport must report the avatar as speaking from the moment an utterance arrives, even
 * when its audio edge (`started_speaking`) comes later, and must never leave it speaking forever
 * when an audio edge is lost.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { SPEECH_PENDING_MS as AVATAR_UTTERANCE_PENDING_MS } from '../../src/transports/speech'
import { startedDaily } from './helpers/daily'

let ctx: Awaited<ReturnType<typeof startedDaily>>

beforeEach(async () => {
  vi.useFakeTimers()
  ctx = await startedDaily()
})

afterEach(() => vi.useRealTimers())

const speaking = () => ctx.handlers.speaking.mock.calls.map(([on]) => on)

describe('audio edges', () => {
  it('reports speaking on started_speaking and silence on stopped_speaking', () => {
    ctx.started()
    ctx.stopped()

    expect(speaking()).toEqual([true, false])
  })

  it('reports an edge only when the state changes', () => {
    ctx.started()
    ctx.started()
    ctx.stopped()
    ctx.stopped()

    expect(speaking()).toEqual([true, false])
  })
})

describe('an utterance that arrives before its audio', () => {
  it('counts as speaking before the line itself is reported', () => {
    const order: string[] = []
    ctx.handlers.speaking.mockImplementation((on: boolean) => order.push(on ? 'speaking' : 'silent'))
    ctx.handlers.transcript.mockImplementation(() => order.push('line'))

    ctx.utter('replica', 'Goodbye.', { inference_id: 'a' })

    expect(order).toEqual(['speaking', 'line'])
  })

  it('stays speaking through the audio, then goes silent on stopped_speaking', () => {
    ctx.utter('replica', 'Goodbye.', { inference_id: 'a' })
    ctx.started()
    vi.advanceTimersByTime(AVATAR_UTTERANCE_PENDING_MS * 3)
    expect(speaking()).toEqual([true])

    ctx.stopped()

    expect(speaking()).toEqual([true, false])
  })

  it('cannot hang: it goes silent when the audio never starts', () => {
    ctx.utter('replica', 'Goodbye.', { inference_id: 'a' })

    vi.advanceTimersByTime(AVATAR_UTTERANCE_PENDING_MS - 1)
    expect(speaking()).toEqual([true])
    vi.advanceTimersByTime(1)

    expect(speaking()).toEqual([true, false])
  })

  it('a second utterance restarts the pending window', () => {
    ctx.utter('replica', 'One.', { inference_id: 'a' })
    vi.advanceTimersByTime(AVATAR_UTTERANCE_PENDING_MS - 100)
    ctx.utter('replica', 'Two.', { inference_id: 'b' })
    vi.advanceTimersByTime(AVATAR_UTTERANCE_PENDING_MS - 100)
    expect(speaking()).toEqual([true])

    vi.advanceTimersByTime(100)

    expect(speaking()).toEqual([true, false])
  })

  it('does not go silent while the audio is still playing when the window ends', () => {
    ctx.utter('replica', 'A long sentence.', { inference_id: 'a' })
    ctx.started()
    vi.advanceTimersByTime(AVATAR_UTTERANCE_PENDING_MS * 2)

    expect(speaking()).toEqual([true])
  })

  it('a duplicate twin does not restart the pending window', () => {
    ctx.utter('replica', 'One.', { inference_id: 'a' })
    vi.advanceTimersByTime(AVATAR_UTTERANCE_PENDING_MS - 100)
    ctx.utter('pal', 'One.', { inference_id: 'a' })
    vi.advanceTimersByTime(100)

    expect(speaking()).toEqual([true, false])
  })

  it('user speech never counts as the avatar speaking', () => {
    ctx.utter('user', 'Hello.', { inference_id: 'u' })

    expect(ctx.handlers.speaking).not.toHaveBeenCalled()
  })

  it('a close by the provider cancels the pending window', () => {
    ctx.utter('replica', 'Goodbye.', { inference_id: 'a' })
    ctx.fire('left-meeting')

    vi.advanceTimersByTime(AVATAR_UTTERANCE_PENDING_MS * 2)

    expect(speaking()).toEqual([true])
  })

  it('stop() cancels the pending window', async () => {
    ctx.utter('replica', 'Goodbye.', { inference_id: 'a' })
    await ctx.transport.stop()
    ctx.handlers.speaking.mockClear()

    vi.advanceTimersByTime(AVATAR_UTTERANCE_PENDING_MS * 2)

    expect(ctx.handlers.speaking).not.toHaveBeenCalled()
  })
})
