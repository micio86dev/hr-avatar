import type { Adapter } from './types'

/** The LiveAvatar transport. Its builder is added with the transport itself (slice 38). */
export const liveavatarAdapter: Adapter = {
  peer: {
    pkg: '@heygen/liveavatar-web-sdk',
    load: () => import('@heygen/liveavatar-web-sdk'),
  },
}
