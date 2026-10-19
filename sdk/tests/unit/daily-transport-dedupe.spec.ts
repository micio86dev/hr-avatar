/**
 * The provider sends every avatar utterance twice over the data channel: once with the role
 * "replica" and once with the legacy role "pal", same inference_id and same speech. Only one of
 * the pair may reach the transcript, and it must be labelled as the avatar, never as the user.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { startedDaily } from './helpers/daily'

let ctx: Awaited<ReturnType<typeof startedDaily>>

beforeEach(async () => {
  vi.useFakeTimers()
  ctx = await startedDaily()
})

afterEach(() => vi.useRealTimers())

describe('replica and pal twins', () => {
  it('reports ONE avatar line for a replica+pal pair with the same inference_id', () => {
    ctx.utter('replica', 'Tell me about a conflict.', { inference_id: 'a' })
    ctx.utter('pal', 'Tell me about a conflict.', { inference_id: 'a' })

    expect(ctx.lines()).toEqual(['avatar:Tell me about a conflict.'])
  })

  it('handles pal arriving BEFORE replica', () => {
    ctx.utter('pal', 'Hello.', { inference_id: 'b' })
    ctx.utter('replica', 'Hello.', { inference_id: 'b' })

    expect(ctx.lines()).toEqual(['avatar:Hello.'])
  })

  it('treats a lone pal as the avatar, never as the user', () => {
    ctx.utter('pal', 'Goodbye.', { inference_id: 'c' })

    expect(ctx.lines()).toEqual(['avatar:Goodbye.'])
  })

  it('leaves a real user line untouched, even with the avatar inference_id', () => {
    ctx.utter('replica', 'Question?', { inference_id: 'd' })
    ctx.utter('user', 'Question?', { inference_id: 'd' })

    expect(ctx.lines()).toEqual(['avatar:Question?', 'user:Question?'])
  })

  it('passes two different inference_ids even with identical speech', () => {
    ctx.utter('replica', 'Thanks.', { inference_id: 'x1' })
    ctx.utter('pal', 'Thanks.', { inference_id: 'x1' })
    ctx.utter('replica', 'Thanks.', { inference_id: 'x2' })
    ctx.utter('pal', 'Thanks.', { inference_id: 'x2' })

    expect(ctx.lines()).toEqual(['avatar:Thanks.', 'avatar:Thanks.'])
  })

  it('remembers only a bounded number of inference_ids', () => {
    ctx.utter('replica', 'First.', { inference_id: 'old' })
    for (let i = 0; i < 60; i++) ctx.utter('replica', `Sentence ${i}`, { inference_id: `n${i}` })
    ctx.utter('pal', 'First.', { inference_id: 'old' })

    // Evicted: the late copy is no longer recognised (bounded memory beats a leak).
    expect(ctx.lines().filter((l) => l === 'avatar:First.')).toHaveLength(2)
  })
})

describe('without an inference_id', () => {
  it('drops the twin with the same speech and turn_idx', () => {
    ctx.utter('replica', 'Hello.', { turn_idx: 3 })
    ctx.utter('pal', 'Hello.', { turn_idx: 3 })

    expect(ctx.lines()).toHaveLength(1)
  })

  it('drops the twin with the same speech inside the time window when turn_idx is absent', () => {
    ctx.utter('replica', 'Hello.')
    vi.advanceTimersByTime(500)
    ctx.utter('pal', 'Hello.')

    expect(ctx.lines()).toHaveLength(1)
  })

  it('keeps the same speech once the window has passed', () => {
    ctx.utter('replica', 'Hello.')
    vi.advanceTimersByTime(5000)
    ctx.utter('replica', 'Hello.')

    expect(ctx.lines()).toHaveLength(2)
  })

  it('keeps the same speech on a different turn_idx', () => {
    ctx.utter('replica', 'Hello.', { turn_idx: 1 })
    ctx.utter('replica', 'Hello.', { turn_idx: 2 })

    expect(ctx.lines()).toHaveLength(2)
  })

  it('treats an empty inference_id as absent', () => {
    ctx.utter('replica', 'Hello.', { inference_id: '' })
    vi.advanceTimersByTime(5000)
    ctx.utter('replica', 'Hello.', { inference_id: '' })

    expect(ctx.lines()).toHaveLength(2)
  })
})
