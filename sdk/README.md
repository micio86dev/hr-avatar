# @hr-avatar/sdk

The browser SDK for HR-Avatar conversations: connect with a publishable key and a conversation's client secret,
render the avatar, read the transcript, control the microphone. TypeScript, no runtime dependencies; the
provider libraries (`@daily-co/daily-js`, `@heygen/liveavatar-web-sdk`) are optional and loaded on demand.

```js
import { HrAvatar } from '@hr-avatar/sdk'

const conversation = await HrAvatar.connect({
  baseUrl: 'https://hra.example.com',
  publishableKey: 'hra_pk_live_xxxxxxxx',
  clientSecret,                                  // from your back end
  mount: document.getElementById('avatar'),
})

conversation.on('transcript', ({ role, text, final }) => final && console.log(role, text))
conversation.on('ended', ({ reason }) => console.log('ended', reason))
```

Everything it can do, its errors, the script-tag build and testing with the mock are in
[docs/sdk-usage.md](../docs/sdk-usage.md); the API it talks to is in [docs/api-usage.md](../docs/api-usage.md).

## Work on it

Bun is the package manager; never npm, pnpm or yarn.

```sh
bun install --frozen-lockfile
bunx tsc --noEmit
bun run test              # Vitest
bun run test:coverage     # thresholds 90
bun run build             # dist/: ESM, CJS, types, ./mock and hr-avatar.global.js (script tag)
bun run size              # each build within 20 KB gzipped
bunx playwright install --with-deps chromium webkit
bunx playwright test      # the script-tag build in a real browser, on Chromium and WebKit
```
