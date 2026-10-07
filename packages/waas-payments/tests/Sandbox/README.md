# Sandbox tests

`AuthorizeNetSandboxTest` runs the Authorize.Net driver against the real sandbox: it checks the credentials, charges Authorize.Net's test Visa for a random amount and voids it, and checks that a card with billing ZIP 46282 (Authorize.Net's decline trigger) comes back declined.

It needs a sandbox account (free at developer.authorize.net) and network access to `apitest.authorize.net`. Requests go through `HTTPS_PROXY` when it is set, trusting `SSL_CERT_FILE` if given.

```shell
AUTHORIZENET_SANDBOX_LOGIN_ID=... AUTHORIZENET_SANDBOX_TRANSACTION_KEY=... composer test -- --testsuite sandbox
```

Without the two variables the tests are skipped, so CI stays green without credentials.

The checkout script has its own tests, with no dependencies: `node --test 'tests/js/*.test.mjs'`.
