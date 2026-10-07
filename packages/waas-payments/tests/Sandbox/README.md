# Sandbox tests

`AuthorizeNetSandboxTest` runs the Authorize.Net driver against the real sandbox: it checks the credentials, charges Authorize.Net's test Visa for a random amount and voids it.

It needs a sandbox account (free at developer.authorize.net) and network access to `apitest.authorize.net`:

```shell
AUTHORIZENET_SANDBOX_LOGIN_ID=... AUTHORIZENET_SANDBOX_TRANSACTION_KEY=... composer test -- --testsuite sandbox
```

Without the two variables the test is skipped, so CI stays green without credentials.
