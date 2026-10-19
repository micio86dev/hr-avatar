/**
 * The speaking signal both provider transports share. The avatar counts as speaking while its
 * audio plays, and from the moment a spoken line arrives until its audio starts, bounded so that a
 * lost audio edge cannot leave it "speaking" forever.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { SPEECH_PENDING_MS, createSpeechTracker } from '../../src/transports/speech'

const report = vi.fn()
let speech: ReturnType<typeof createSpeechTracker>

beforeEach(() => {
  vi.useFakeTimers()
  report.mockClear()
  speech = createSpeechTracker(report)
})

afterEach(() => vi.useRealTimers())

const calls = () => report.mock.calls.map(([on]) => on)

describe('audio edges', () => {
  it('reports speaking while the audio plays and silence when it stops', () => {
    speech.audio(true)
    speech.audio(false)

    expect(calls()).toEqual([true, false])
  })

  it('reports an edge only when the state changes', () => {
    speech.audio(true)
    speech.audio(true)
    speech.audio(false)
    speech.audio(false)

    expect(calls()).toEqual([true, false])
  })

  it('says whether the audio is playing', () => {
    expect(speech.playing()).toBe(false)
    speech.audio(true)
    expect(speech.playing()).toBe(true)
    speech.audio(false)
    expect(speech.playing()).toBe(false)
  })
})

describe('a line that arrives before its audio', () => {
  it('counts as speaking, then goes silent when the audio never starts', () => {
    speech.expectAudio()
    vi.advanceTimersByTime(SPEECH_PENDING_MS - 1)
    expect(calls()).toEqual([true])

    vi.advanceTimersByTime(1)

    expect(calls()).toEqual([true, false])
    expect(speech.playing()).toBe(false)
  })

  it('stays speaking through the audio and past the window', () => {
    speech.expectAudio()
    speech.audio(true)
    vi.advanceTimersByTime(SPEECH_PENDING_MS * 3)

    expect(calls()).toEqual([true])
  })

  it('a later line restarts the window', () => {
    speech.expectAudio()
    vi.advanceTimersByTime(SPEECH_PENDING_MS - 100)
    speech.expectAudio()
    vi.advanceTimersByTime(SPEECH_PENDING_MS - 100)
    expect(calls()).toEqual([true])

    vi.advanceTimersByTime(100)

    expect(calls()).toEqual([true, false])
  })

  it('cancel() drops the window without reporting', () => {
    speech.expectAudio()
    speech.cancel()
    vi.advanceTimersByTime(SPEECH_PENDING_MS * 2)

    expect(calls()).toEqual([true])
  })
})
