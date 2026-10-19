# SDK usage

`@hr-avatar/sdk` joins a conversation from the browser. It makes the three browser calls to the service
(connect, send events, end), loads the transport the service picked, renders the avatar, and gives you the
conversation: its state, its transcript and a few controls. It has no runtime dependencies; the provider
libraries are optional and loaded on demand.

You need a **publishable key** and a conversation's **client secret**. Your back end creates the conversation with
its secret key and gives the browser the `client_secret` ([API usage](api-usage.md#5-create-a-conversation)).

## Install

The package is not published yet. Build it and install the folder (or copy `dist/`):

```sh
cd sdk
bun install --frozen-lockfile
bun run build          # dist/: ESM, CJS, types, ./mock and the script-tag build
```

```sh
bun add ../path/to/hr-avatar/sdk      # in your app
```

Both ESM and CJS are provided, with types. Each build is within **20 KB** gzipped, which `bun run size` checks.

## Connect

```js
import { HrAvatar } from '@hr-avatar/sdk'

const conversation = await HrAvatar.connect({
  baseUrl: 'https://hra.example.com',
  publishableKey: 'hra_pk_live_xxxxxxxx',
  clientSecret,
  mount: document.getElementById('avatar'),
})
```

`HrAvatar.connect` resolves while the conversation is still `connecting`; subscribe to see it go live. It rejects
with an `HrAvatarError` if the client secret is malformed, the service refuses (an expired secret, an origin not on
the key's list, a conversation already connected), or the transport cannot be loaded or joined. Whatever the
reason, a provider session that was already started is released first.

### Options

| Option | Type | Meaning |
|---|---|---|
| `baseUrl` | string | Where the service is, without `/v1` and with or without a trailing slash. |
| `publishableKey` | string | The `hra_pk_…` key. Its allowed origins must include the page's origin. |
| `clientSecret` | string | The conversation's `client_secret`. The SDK reads the conversation id from it (it does not verify it; the service does). |
| `mount` | `HTMLElement` | Where the avatar is rendered. A `<video>` is used as it is; any other element gets a `<video>` created inside it. |
| `audioDeviceId` | string, optional | The microphone to use, from `navigator.mediaDevices.enumerateDevices()`. |
| `completionPhrases` | string[], optional | Phrases that end the conversation: see below. |
| `forwardTranscript` | boolean, default `true` | `false` stops the **utterances** being sent to the service. The `live` and `error` lifecycle events are always sent, because for a provider without callbacks the browser's `live` is the only thing that moves the conversation to `live`. |
| `transports` | `{ daily?, liveavatar?, mock? }`, optional | Replaces a whole transport with a factory (tests, custom hosts). The provider library is then not imported. |
| `loaders` | `{ daily?, liveavatar? }`, optional | Replaces only the import of a provider library, for example with a CDN import. |

### Completion phrases

With `completionPhrases: ['goodbye', 'thank you for your time']`, once the **avatar** says a line containing a
phrase (case-insensitive substring; the user's own words never count) and then stays silent for 1500 ms, the SDK
ends the conversation with the reason `completion_phrase`. A blank phrase is ignored.

## The conversation

```js
conversation.on('state', (state) => { /* 'connecting' | 'live' | 'ended' | 'error' */ })
conversation.on('transcript', ({ role, text, final, occurredAt }) => { /* role: 'user' | 'avatar' */ })
conversation.on('error', (error) => { /* an HrAvatarError; the conversation may still be usable */ })
conversation.on('ended', ({ reason }) => { /* see below */ })
```

`on` returns the function that unsubscribes. A `state` handler is called at once with the current state, so a
host sees the whole sequence even though `connect` resolved while `connecting`. A `transcript` entry with
`final: false` is an interim fragment of a line still being spoken; `final: true` is the finished line, and only
those are forwarded to the service. A handler that throws is reported with `reportError` and does not affect the SDK
or the other handlers.

`conversation.state` and `conversation.id` are readable at any time.

`ended.reason` is one of:

| Reason | Meaning |
|---|---|
| `user` | `conversation.end()` was called |
| `completion_phrase` | a completion phrase was said |
| `server` | the service ended it (your back end, the maximum duration, or it expired) |
| `provider` | the provider closed the connection |
| `error` | the transport failed (the state is `error`, not `ended`) |

### Controls

| Call | Does |
|---|---|
| `end()` | Ends the conversation: flushes the transcript, tells the service, leaves the provider and releases the camera and microphone. Safe to call twice. |
| `toggleMic()` | Flips the microphone and returns the new muted state at once (a boolean). A failure arrives as an `error` event. |
| `setMicMuted(muted)` | Sets it, and resolves when the transport has. |
| `say(text)` | The avatar speaks `text` verbatim. |
| `interrupt()` | Stops what the avatar is saying. |
| `sendContext(payload)` | Gives the avatar extra context mid-conversation, a string or an object. Rejects with `unsupported_operation` where the provider cannot take it. |
| `whenAvatarSilent(ms)` | Resolves when the avatar has said nothing for `ms` milliseconds (at once if it has been silent that long, or never spoke), and when the conversation ends, so it never hangs. |

Any control used after the conversation ended rejects with `conversation_ended`.

### What is sent to the service

Finished lines and the `live` signal are queued in memory (never written to browser storage) and sent in batches
when 20 are waiting or after 2 seconds, in order. A batch that fails with a network error or a 429/5xx is retried
with backoff. A `409 conversation_closed` or a `401` stops the forwarding for good and emits an `error` with the
code `forwarding_failed`, without ending the conversation. When the page is hidden, what is left is sent with a
`keepalive` request.

## Errors

Every error the SDK rejects with or emits is an `HrAvatarError`:

```js
try {
  await HrAvatar.connect(options)
} catch (error) {
  if (error instanceof HrAvatarError) {
    error.code         // a stable machine value
    error.message      // for people; never a secret or a provider's words
    error.recoverable  // true when trying again may work (a network failure, a 429 or a 5xx)
    error.status       // the HTTP status, when the service answered
    error.request_id   // quote it to whoever runs the service
  }
}
```

`code` is either a code of the service ([API usage](api-usage.md#10-errors-pages-and-limits): `already_connected`,
`client_secret_expired`, `origin_not_allowed`, `conversation_closed`, `rate_limited`, …) or one of the SDK's own:

| Code | When |
|---|---|
| `invalid_client_secret` | The client secret is not a well-formed token. Nothing was sent. |
| `network_error` | A request did not reach the service. `recoverable`. |
| `unexpected_response` | The service answered with something that is not JSON. |
| `unsupported_transport` | The service named a transport this SDK does not have. |
| `provider_library_missing` | The conversation needs a provider library that is not installed or not reachable. See the next section. |
| `provider_unavailable` | Joining the provider failed. `recoverable`. |
| `transport_error` | A control was used with no open connection, or the connection failed. |
| `unsupported_operation` | `sendContext` on a provider that cannot take it. |
| `conversation_ended` | A control was used after the conversation ended. |
| `forwarding_failed` | The transcript could not be forwarded any more. |

## The script-tag build and provider libraries

The service names the transport a conversation needs (`daily` for Tavus, `liveavatar` for HeyGen LiveAvatar,
`mock` for the mock provider), and the SDK imports the matching library **only then**. Both libraries are optional
peer dependencies, pinned to the versions the SDK is tested with:

| Transport | Package | Version |
|---|---|---|
| `daily` | `@daily-co/daily-js` | 0.91.0 |
| `liveavatar` | `@heygen/liveavatar-web-sdk` | 0.0.18 |

With a bundler, install the one you use (`bun add @daily-co/daily-js@0.91.0`). Without one, `hr-avatar.global.js`
is a script-tag build that exposes `window.HrAvatar`; it has no bundler to resolve a package, so you pass a loader
that imports the library from a CDN:

```html
<video id="avatar" playsinline autoplay></video>
<script src="/hr-avatar.global.js"></script>
<script type="module">
  const conversation = await window.HrAvatar.connect({
    baseUrl: 'https://hra.example.com',
    publishableKey: 'hra_pk_live_xxxxxxxx',
    clientSecret,
    mount: document.getElementById('avatar'),
    loaders: {
      daily: () => import('https://esm.sh/@daily-co/daily-js@0.91.0'),
      liveavatar: () => import('https://esm.sh/@heygen/liveavatar-web-sdk@0.0.18'),
    },
  })
</script>
```

A loader must resolve to the library's module, the value `import('<package>')` gives a bundler, so any URL that
serves the pinned version as an ES module with the package's exports works; the two above (esm.sh) are examples.
The SDK's own tests exercise loaders with stand-in libraries, not with a CDN, so try yours once.

In the script-tag build `connect` is at the top level of `window.HrAvatar` (`window.HrAvatar.connect`), where the
package entry has `HrAvatar.connect`; `HrAvatarError` and `createMockTransport` are there too.

If a loader is missing, or its import fails, `connect` rejects with **`provider_library_missing`**, after
releasing the provider session the service had started, and the message names the package.

## Testing your integration with the mock

`@hr-avatar/sdk/mock` exports `createMockTransport`, a deterministic transport with no network or media, that
supports every control. Pass it as the `mock` transport:

```js
import { HrAvatar } from '@hr-avatar/sdk'
import { createMockTransport } from '@hr-avatar/sdk/mock'

const transport = createMockTransport({ script: { avatarLines: ['Hello from the mock avatar'] } })

const conversation = await HrAvatar.connect({
  baseUrl, publishableKey, clientSecret, mount,
  transports: { mock: () => transport },
})

transport.emitUserLine('Hi')          // a final user line
transport.emitInterim('user', 'H')    // an interim fragment
transport.startSpeaking()             // the avatar starts and stops speaking
transport.close()                     // the provider side closes the connection
transport.commands                    // what the conversation asked of it: mic, say, interrupt, context, stop
```

Against a service running the **mock provider** (the examples in [API usage](api-usage.md)), the conversation
the service starts names the `mock` transport, so this runs the real service flow without a provider account.
