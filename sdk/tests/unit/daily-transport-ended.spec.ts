/**
 * A conversation that ends without the SDK asking (the provider's ceiling, a dropped call) is
 * reported as exactly one `closed`, never as an error. The observed sequence is replayed through a
 * fake call object: conversation.left, system.shutdown, the avatar track stopping, the participant
 * leaving, Daily's own "Meeting has ended" error, then left-meeting.
 */
import { describe, expect, it } from 'vitest'
import { startedDaily } from './helpers/daily'

describe('unannounced end', () => {
  it('reports exactly one close for the observed end sequence, and no failure', async () => {
    const { fire, message, handlers } = await startedDaily()

    message({ message_type: 'conversation', event_type: 'conversation.left' })
    message({ message_type: 'conversation', event_type: 'system.shutdown' })
    fire('track-stopped', { participant: { local: false } })
    fire('participant-left', { participant: { local: false } })
    fire('error', { errorMsg: 'Meeting has ended' })
    fire('left-meeting')
    fire('left-meeting')

    expect(handlers.closed).toHaveBeenCalledTimes(1)
    expect(handlers.failed).not.toHaveBeenCalled()
  })

  it('a deliberate stop() reports no close, even though leaving fires left-meeting', async () => {
    const { transport, call, handlers } = await startedDaily()

    await transport.stop()

    expect(call.leave).toHaveBeenCalledTimes(1)
    expect(handlers.closed).not.toHaveBeenCalled()
  })

  it('stays silent when left-meeting arrives after a stop', async () => {
    const { transport, fire, handlers } = await startedDaily()
    await transport.stop()

    fire('left-meeting')

    expect(handlers.closed).not.toHaveBeenCalled()
  })
})
