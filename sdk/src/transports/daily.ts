import type { Adapter } from './types'

/** The Daily transport. Its builder is added with the transport itself (slice 37). */
export const dailyAdapter: Adapter = {
  peer: { pkg: '@daily-co/daily-js', load: () => import('@daily-co/daily-js') },
}
