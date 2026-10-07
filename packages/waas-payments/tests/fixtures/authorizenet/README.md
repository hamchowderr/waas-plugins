# Authorize.Net response fixtures

Recorded from the Authorize.Net sandbox (`apitest.authorize.net`) on 2026-10-07, except where noted. Every response starts with the UTF-8 byte order mark Authorize.Net puts on JSON responses.

- `charge-approved.json`, `charge-declined.json`, `refund-not-settled.json`, `void-approved.json`, `auth-failed.json`: the raw bodies, unchanged. The decline was triggered with billing ZIP 46282 (Authorize.Net's testing guide). A declined charge has `messages.resultCode` "Ok": only `transactionResponse.responseCode` (2) says it was declined.
- `merchant-details.json`, `merchant-details-no-client-key.json`: a recorded `getMerchantDetailsResponse` with the account's identifying values (merchant name, gateway ID, Public Client Key, business and contact details) replaced by made-up ones. The no-client-key variant has `publicClientKey` removed.
- `charge-held-for-review.json`, `refund-approved.json`: **not recordings.** A held charge needs fraud filters set up on the account, and a refund needs a charge that has settled (the sandbox settles once a day). They follow the documented format (`AnetApiSchema.xsd` in authorizenet/sdk-php and the API reference).

To refresh them, run the `sandbox` test suite with `AUTHORIZENET_PRINT_RESPONSES=1` (see `tests/Sandbox/README.md`) and copy the bodies it prints.
