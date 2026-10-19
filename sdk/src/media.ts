/** The element the avatar is rendered into: a `<video>` mount is used as-is, anything else gets one. */
export function resolveMount(mount: HTMLElement): HTMLVideoElement {
  if (mount instanceof HTMLVideoElement) return mount

  const video = document.createElement('video')
  video.autoplay = true
  video.setAttribute('playsinline', '')
  mount.appendChild(video)
  return video
}

/** Stops the tracks the avatar was rendered from and detaches them from the element. */
export function releaseMedia(video: HTMLVideoElement): void {
  const stream = video.srcObject
  if (typeof MediaStream !== 'undefined' && stream instanceof MediaStream) {
    for (const track of stream.getTracks()) track.stop()
  }
  video.srcObject = null
}
