/**
 * Findings of the full-coverage reviews rv06 and rv07: what a transport reports when the call ends or is
 * stopped while it is still joining, and when the provider closes it while the avatar is speaking.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HrAvatarError } from '../../src/errors'
import { createDailyTransport } from '../../src/transports/daily'
import { createLiveAvatarTransport } from '../../src/transports/liveavatar'
import { CONVERSATION_URL, fakeDaily, startedDaily, transportEvents } from './helpers/daily'
import { SESSION_TOKEN, fakeLiveAvatar, startedLiveAvatar, videoMount } from './helpers/liveavatar'

beforeEach(() => {
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
})

async function rejection(promise: Promise<unknown>): Promise<HrAvatarError> {
  const error = await promise.then(
    () => undefined,
    (e: unknown) => e,
  )
  expect(error).toBeInstanceOf(HrAvatarError)
  return error as HrAvatarError
}

/** A promise settled by the test, standing for a join or a session start that is still in flight. */
function deferred() {
  let resolve!: () => void
  const promise = new Promise<void>((done) => {
    resolve = done
  })
  return { promise, resolve }
}

describe('the Daily transport', () => {
  const join = { provider: 'tavus', transport: 'daily', conversation_url: CONVERSATION_URL }

  it('reports neither ready nor a live start when stop() ran while the join was pending', async () => {
    const daily = fakeDaily()
    const events = transportEvents()
    const joining = deferred()
    daily.call.join.mockReturnValue(joining.promise)
    const transport = createDailyTransport(daily.library)

    const started = rejection(transport.start({ join, mount: document.createElement('video') }, events.handlers))
    await transport.stop()
    joining.resolve()
    const error = await started

    expect(error.code).toBe('provider_unavailable')
    expect(events.handlers.ready).not.toHaveBeenCalled()
  })

  it('does not report ready from a joined-meeting that arrives after stop() during the join', async () => {
    const daily = fakeDaily()
    const events = transportEvents()
    const joining = deferred()
    daily.call.join.mockReturnValue(joining.promise)
    const transport = createDailyTransport(daily.library)

    const started = rejection(transport.start({ join, mount: document.createElement('video') }, events.handlers))
    await transport.stop()
    daily.fire('joined-meeting')
    joining.resolve()
    await started

    expect(events.handlers.ready).not.toHaveBeenCalled()
  })

  it('rejects the start, after the close it reported, when the provider ended the call during the join', async () => {
    const daily = fakeDaily()
    const events = transportEvents()
    const joining = deferred()
    daily.call.join.mockReturnValue(joining.promise)

    const started = rejection(createDailyTransport(daily.library).start({ join, mount: document.createElement('video') }, events.handlers))
    daily.fire('left-meeting')
    joining.resolve()
    const error = await started

    expect(error.code).toBe('provider_unavailable')
    expect(events.handlers.closed).toHaveBeenCalledTimes(1)
    expect(events.handlers.ready).not.toHaveBeenCalled()
  })

  it.each([
    ['audio playing', (daily: Awaited<ReturnType<typeof startedDaily>>) => daily.started()],
    ['a spoken line waiting for its audio', (daily: Awaited<ReturnType<typeof startedDaily>>) => daily.utter('replica', 'Hello')],
  ])('tells the consumer the avatar went silent before it reports the close (%s)', async (_label, speak) => {
    const daily = await startedDaily()
    speak(daily)
    expect(daily.handlers.speaking).toHaveBeenLastCalledWith(true)

    daily.fire('left-meeting')

    expect(daily.handlers.speaking).toHaveBeenLastCalledWith(false)
    expect(daily.handlers.speaking.mock.invocationCallOrder.at(-1)).toBeLessThan(
      daily.handlers.closed.mock.invocationCallOrder[0] as number,
    )
  })

  it('reports no silence when the avatar was not speaking', async () => {
    const daily = await startedDaily()

    daily.fire('left-meeting')

    expect(daily.handlers.speaking).not.toHaveBeenCalled()
  })
})

describe('the LiveAvatar transport', () => {
  const material = { provider: 'heygen', transport: 'liveavatar', session_token: SESSION_TOKEN }

  it('rejects the start, after the close it reported, when the session disconnected while it was starting', async () => {
    const fake = fakeLiveAvatar()
    const events = transportEvents()
    const starting = deferred()
    fake.session.start.mockReturnValue(starting.promise)
    const transport = createLiveAvatarTransport(fake.library)

    const started = rejection(transport.start({ join: material, mount: videoMount() }, events.handlers))
    fake.fire('session.disconnected')
    starting.resolve()
    const error = await started

    expect(error.code).toBe('provider_unavailable')
    expect(events.handlers.closed).toHaveBeenCalledTimes(1)
    expect(events.handlers.ready).not.toHaveBeenCalled()
  })

  it('reports neither ready nor success when stop() ran while the session was starting', async () => {
    const fake = fakeLiveAvatar()
    const events = transportEvents()
    const starting = deferred()
    fake.session.start.mockReturnValue(starting.promise)
    const transport = createLiveAvatarTransport(fake.library)

    const started = rejection(transport.start({ join: material, mount: videoMount() }, events.handlers))
    await transport.stop()
    starting.resolve()
    await started

    expect(events.handlers.ready).not.toHaveBeenCalled()
  })

  it('tells the consumer the avatar went silent before it reports the close', async () => {
    const live = await startedLiveAvatar()
    live.fire('avatar.speak_started')
    expect(live.handlers.speaking).toHaveBeenLastCalledWith(true)

    live.fire('session.disconnected')

    expect(live.handlers.speaking).toHaveBeenLastCalledWith(false)
    expect(live.handlers.speaking.mock.invocationCallOrder.at(-1)).toBeLessThan(
      live.handlers.closed.mock.invocationCallOrder[0] as number,
    )
  })
})
