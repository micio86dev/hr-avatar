/**
 * Entry of the script-tag (IIFE) build: what a page gets as `window.HrAvatar`. `connect` sits at the
 * top level here, unlike the package entry where it hangs off the `HrAvatar` object.
 */
export { connect } from './connect'
export { HrAvatarError } from './errors'
export { createMockTransport } from './transports/mock'
