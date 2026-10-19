import { describe, expect, it, vi } from 'vitest'
import { COMPLETION_SETTLE_MS, watchCompletion } from '../../src/completion'

type Entry = { role: 'user' | 'avatar'; text: string; final: boolean }

function setup(phrases?: string[]) {
  let silent: () => void = () => undefined
  const whenAvatarSilent = vi.fn(
    (_ms: number) => new Promise<void>((resolve) => (silent = resolve)),
  )
  const onComplete = vi.fn()
  const watch = watchCompletion({ phrases, whenAvatarSilent, onComplete })
  return { watch, whenAvatarSilent, onComplete, release: () => silent() }
}

const avatar = (text: string, final = true): Entry => ({ role: 'avatar', text, final })

describe('completion phrases', () => {
  it('ends the conversation after the avatar has been silent for 1500 ms', async () => {
    const { watch, whenAvatarSilent, onComplete, release } = setup(['thank you, goodbye'])

    watch(avatar('Thank you, goodbye!'))
    await Promise.resolve()

    expect(COMPLETION_SETTLE_MS).toBe(1500)
    expect(whenAvatarSilent).toHaveBeenCalledWith(1500)
    expect(onComplete).not.toHaveBeenCalled()

    release()
    await vi.waitFor(() => expect(onComplete).toHaveBeenCalledTimes(1))
  })

  it('matches case-insensitively in both directions', () => {
    const upper = setup(['THANK YOU'])
    upper.watch(avatar('well, thank you very much'))
    const lower = setup(['thank you'])
    lower.watch(avatar('THANK YOU VERY MUCH'))

    expect(upper.whenAvatarSilent).toHaveBeenCalledTimes(1)
    expect(lower.whenAvatarSilent).toHaveBeenCalledTimes(1)
  })

  it('matches any of several phrases', () => {
    const { watch, whenAvatarSilent } = setup(['first phrase', 'second phrase'])

    watch(avatar('and that was the second phrase, really'))

    expect(whenAvatarSilent).toHaveBeenCalledTimes(1)
  })

  it('ignores the user, even when they say the phrase', () => {
    const { watch, whenAvatarSilent } = setup(['thank you, goodbye'])

    watch({ role: 'user', text: 'thank you, goodbye', final: true })

    expect(whenAvatarSilent).not.toHaveBeenCalled()
  })

  it('ignores interim avatar fragments', () => {
    const { watch, whenAvatarSilent } = setup(['thank you, goodbye'])

    watch(avatar('thank you, goodbye', false))

    expect(whenAvatarSilent).not.toHaveBeenCalled()
  })

  it('ignores avatar speech without a phrase', () => {
    const { watch, whenAvatarSilent } = setup(['thank you, goodbye'])

    watch(avatar('How can I help you today?'))

    expect(whenAvatarSilent).not.toHaveBeenCalled()
  })

  it.each([
    ['undefined', undefined],
    ['an empty list', []],
    ['only blank phrases', ['', '   ']],
  ])('infers nothing from avatar speech with %s', (_name, phrases) => {
    const { watch, whenAvatarSilent, onComplete } = setup(phrases)

    watch(avatar('thank you, goodbye'))

    expect(whenAvatarSilent).not.toHaveBeenCalled()
    expect(onComplete).not.toHaveBeenCalled()
  })

  it('completes only once however many lines match', async () => {
    const { watch, whenAvatarSilent, onComplete, release } = setup(['goodbye'])

    watch(avatar('goodbye'))
    watch(avatar('goodbye again'))
    release()
    await vi.waitFor(() => expect(onComplete).toHaveBeenCalledTimes(1))
    watch(avatar('one more goodbye'))

    expect(whenAvatarSilent).toHaveBeenCalledTimes(1)
    expect(onComplete).toHaveBeenCalledTimes(1)
  })
})
