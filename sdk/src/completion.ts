/** How long the avatar must stay silent after a completion phrase before the conversation ends. */
export const COMPLETION_SETTLE_MS = 1500

export interface CompletionOptions {
  /** `completionPhrases` from the connect options. Without usable phrases nothing is inferred. */
  phrases: string[] | undefined
  whenAvatarSilent: (ms: number) => Promise<void>
  onComplete: () => void
}

interface Heard {
  role: 'user' | 'avatar'
  text: string
  final: boolean
}

/**
 * Returns a transcript listener. A final avatar line containing a phrase (case-insensitive
 * substring) lets the avatar finish speaking, waits for `COMPLETION_SETTLE_MS` of silence, then
 * calls `onComplete` once. The user's own words never count.
 */
export function watchCompletion(options: CompletionOptions): (heard: Heard) => void {
  // A blank phrase is a substring of every line, so it would end the conversation at once.
  const phrases = (options.phrases ?? []).map((p) => p.trim().toLowerCase()).filter(Boolean)
  let triggered = false

  return (heard) => {
    if (triggered || phrases.length === 0 || heard.role !== 'avatar' || !heard.final) return
    const text = heard.text.toLowerCase()
    if (!phrases.some((phrase) => text.includes(phrase))) return

    triggered = true
    void options.whenAvatarSilent(COMPLETION_SETTLE_MS).then(options.onComplete)
  }
}
