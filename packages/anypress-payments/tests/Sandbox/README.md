# Sandbox tests

`AuthorizeNetSandboxTest` runs the Authorize.Net driver against the real sandbox:
- It checks the credentials, charges Authorize.Net's test Visa for a random amount and voids it.
- It checks that a card with billing ZIP 46282 (Authorize.Net's decline trigger) comes back declined.
- It runs the subscription flow with saved cards (CIM):
  - a first charge that saves the card, then a renewal charged to it
  - a second card added to the same customer profile
  - a saved card that declines on renewal
  - deleting a saved card
  - the lookups used to reconcile an unanswered renewal

The charges are voided at the end.

It needs a sandbox account (free at developer.authorize.net) and network access to `apitest.authorize.net`. Requests go through `HTTPS_PROXY` when it is set, trusting `SSL_CERT_FILE` if given.

```shell
AUTHORIZENET_SANDBOX_LOGIN_ID=... AUTHORIZENET_SANDBOX_TRANSACTION_KEY=... composer test -- --testsuite sandbox
```

The `AUTHNET_SANDBOX_LOGIN_ID` / `AUTHNET_SANDBOX_TRANSACTION_KEY` names work too. Without the variables the tests are skipped, so CI stays green without credentials.

The checkout script has its own tests, with no dependencies: `node --test 'tests/js/*.test.mjs'`.
