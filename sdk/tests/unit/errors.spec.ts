import { describe, expect, it } from 'vitest'
import { HrAvatarError } from '../../src/errors'

describe('HrAvatarError', () => {
  it('carries code, message, recoverable, status and request_id', () => {
    const error = new HrAvatarError({
      code: 'already_connected',
      message: 'Conversation is already connected.',
      recoverable: false,
      status: 409,
      request_id: 'req-1',
    })

    expect(error.code).toBe('already_connected')
    expect(error.message).toBe('Conversation is already connected.')
    expect(error.recoverable).toBe(false)
    expect(error.status).toBe(409)
    expect(error.request_id).toBe('req-1')
  })

  it('is an Error with a stable name', () => {
    const error = new HrAvatarError({ code: 'x', message: 'y' })

    expect(error).toBeInstanceOf(Error)
    expect(error).toBeInstanceOf(HrAvatarError)
    expect(error.name).toBe('HrAvatarError')
  })

  it('defaults to not recoverable and leaves status and request_id unset', () => {
    const error = new HrAvatarError({ code: 'x', message: 'y' })

    expect(error.recoverable).toBe(false)
    expect(error.status).toBeUndefined()
    expect(error.request_id).toBeUndefined()
  })
})
