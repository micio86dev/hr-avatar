/** The script-tag build exposes `connect` directly on `window.HrAvatar`, not nested one level down. */
import { describe, expect, it } from 'vitest'
import * as entry from '../../src/global'
import { HrAvatar, HrAvatarError, createMockTransport } from '../../src'

describe('the global entry', () => {
  it('exposes exactly connect, the error type and the mock transport factory', () => {
    expect(Object.keys(entry).sort()).toEqual(['HrAvatarError', 'connect', 'createMockTransport'])
  })

  it('is the same implementation as the package entry', () => {
    expect(entry.connect).toBe(HrAvatar.connect)
    expect(entry.HrAvatarError).toBe(HrAvatarError)
    expect(entry.createMockTransport).toBe(createMockTransport)
  })
})
