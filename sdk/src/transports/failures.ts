import { HrAvatarError } from '../errors'

/**
 * Joining failed. A stable code and message, never the library's text: that names the vendor, its
 * hosts and room urls, which a host must not be able to render.
 */
export const providerUnavailable = () =>
  new HrAvatarError({
    code: 'provider_unavailable',
    message: 'The avatar provider is unavailable',
    recoverable: true,
  })

/** A control was used with no open connection, or the connection refused it. */
export const connectionNotOpen = () =>
  new HrAvatarError({ code: 'transport_error', message: 'The avatar connection is not open' })
