import { HrAvatar, createMockTransport } from '../../../src'
import type { ConnectOptions } from '../../../src'
import type { MockScript } from '../../../src/transports/mock'
import { BASE_URL, CLIENT_SECRET, PUBLISHABLE_KEY } from './service'

/** Connects through the public entry on a scripted mock transport. Call `fakeService()` first. */
export async function open(
  script?: MockScript,
  extra: Partial<ConnectOptions> = {},
) {
  const mock = createMockTransport(script ? { script } : undefined)
  const conversation = await HrAvatar.connect({
    baseUrl: BASE_URL,
    publishableKey: PUBLISHABLE_KEY,
    clientSecret: CLIENT_SECRET,
    mount: document.createElement('div'),
    transports: { mock: () => mock },
    ...extra,
  })
  return { conversation, mock }
}
