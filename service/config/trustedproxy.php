<?php

/*
| The proxies trusted to say who called (the X-Forwarded-* headers), read by the framework's TrustProxies
| middleware. It decides what `$request->ip()` is, and so whose budget the failed-authentication and
| provider-callback limiters spend.
|
| Unset trusts none: a forwarded address is ignored and every caller behind a proxy shares the proxy's budget.
| `*` trusts whatever connects, which is right only when the service is reachable through the proxy alone (a
| platform edge); otherwise list the proxies' addresses, comma separated. Trusting a peer that is not a
| proxy lets a caller choose the address it is counted under.
*/
return [
    'proxies' => env('HRA_TRUSTED_PROXIES') ?: null,
];
