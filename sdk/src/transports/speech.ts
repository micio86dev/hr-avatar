/**
 * How long a spoken line counts as speaking while its audio has not started. The line can arrive
 * before the audio's start edge; the bound keeps a lost edge from leaving the avatar "speaking".
 */
export const SPEECH_PENDING_MS = 2_000

/**
 * The avatar's speaking signal for a transport. It is speaking while its audio plays, and from the
 * moment a spoken line arrives (`expectAudio`) until that audio starts or the window ends. Only
 * changes are reported.
 */
export function createSpeechTracker(report: (speaking: boolean) => void) {
  let audioPlaying = false
  let awaitingAudio = false
  let sent = false
  let timer: ReturnType<typeof setTimeout> | null = null

  const sync = (): void => {
    const speaking = audioPlaying || awaitingAudio
    if (speaking === sent) return
    sent = speaking
    report(speaking)
  }

  const cancel = (): void => {
    awaitingAudio = false
    if (timer !== null) clearTimeout(timer)
    timer = null
  }

  return {
    /** The audio started (`true`) or stopped (`false`). */
    audio(playing: boolean): void {
      audioPlaying = playing
      cancel()
      sync()
    },
    /** A spoken line arrived: speaking from now, until its audio starts or the window ends. */
    expectAudio(): void {
      if (timer !== null) clearTimeout(timer)
      awaitingAudio = true
      timer = setTimeout(() => {
        cancel()
        sync()
      }, SPEECH_PENDING_MS)
      sync()
    },
    playing: (): boolean => audioPlaying,
    /** Drops the window without reporting: the transport is going away. */
    cancel,
    /** The provider ended the call: whatever was reported as speaking is told to have stopped, once. */
    silence(): void {
      audioPlaying = false
      cancel()
      sync()
    },
  }
}
