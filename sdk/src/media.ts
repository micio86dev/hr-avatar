/** The element the avatar is rendered into: a `<video>` mount is used as-is, anything else gets one. */
export function resolveMount(mount: HTMLElement): HTMLVideoElement {
  if (mount instanceof HTMLVideoElement) return mount

  const video = document.createElement('video')
  video.autoplay = true
  video.setAttribute('playsinline', '')
  mount.appendChild(video)
  return video
}
