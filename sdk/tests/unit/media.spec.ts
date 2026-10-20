import { afterEach, describe, expect, it, vi } from 'vitest'
import { releaseMedia, resolveMount } from '../../src/media'

describe('resolveMount', () => {
  it('uses a <video> mount as-is', () => {
    const video = document.createElement('video')

    expect(resolveMount(video)).toBe(video)
    expect(video.childElementCount).toBe(0)
  })

  it('creates a <video> inside any other element', () => {
    const container = document.createElement('div')

    const video = resolveMount(container)

    expect(video).toBeInstanceOf(HTMLVideoElement)
    expect(video.parentElement).toBe(container)
    expect(container.childElementCount).toBe(1)
  })

  it('lets the avatar play on its own, inline and with sound', () => {
    const video = resolveMount(document.createElement('section'))

    expect(video.autoplay).toBe(true)
    expect(video.hasAttribute('playsinline')).toBe(true)
    expect(video.muted).toBe(false)
  })

  it('creates a separate <video> for each call', () => {
    const container = document.createElement('div')

    expect(resolveMount(container)).not.toBe(resolveMount(container))
  })
})

describe('releaseMedia', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('stops every track of the stream and detaches it', () => {
    const stop = vi.fn()
    class FakeStream {
      getTracks = () => [{ stop }, { stop }]
    }
    vi.stubGlobal('MediaStream', FakeStream)
    const video = document.createElement('video')
    video.srcObject = new FakeStream() as unknown as MediaStream

    releaseMedia(video)

    expect(stop).toHaveBeenCalledTimes(2)
    expect(video.srcObject).toBeNull()
  })

  it('leaves an element without a stream alone', () => {
    const video = document.createElement('video')

    expect(() => releaseMedia(video)).not.toThrow()
    expect(video.srcObject).toBeNull()
  })

  it('copes with a browser that has no MediaStream', () => {
    vi.stubGlobal('MediaStream', undefined)
    const video = document.createElement('video')

    expect(() => releaseMedia(video)).not.toThrow()
  })
})
