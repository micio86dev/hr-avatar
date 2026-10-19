export interface HrAvatarErrorInit {
  code: string
  message: string
  recoverable?: boolean
  status?: number
  request_id?: string
}

/**
 * The only error type the SDK rejects or emits. `code` is a stable machine value: either a service
 * Problem+JSON code (`already_connected`, `client_secret_expired`, ...) or an SDK code
 * (`invalid_client_secret`, `conversation_ended`, `unsupported_operation`, `provider_library_missing`).
 */
export class HrAvatarError extends Error {
  readonly code: string
  readonly recoverable: boolean
  readonly status?: number
  readonly request_id?: string

  constructor(init: HrAvatarErrorInit) {
    super(init.message)
    // Set explicitly: a minified build mangles the class name.
    this.name = 'HrAvatarError'
    this.code = init.code
    this.recoverable = init.recoverable ?? false
    if (init.status !== undefined) this.status = init.status
    if (init.request_id !== undefined) this.request_id = init.request_id
  }
}
