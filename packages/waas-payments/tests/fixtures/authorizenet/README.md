# Authorize.Net response fixtures

Recorded from the Authorize.Net sandbox (`apitest.authorize.net`) on 2026-10-07, except where noted. Every response starts with the UTF-8 byte order mark Authorize.Net puts on JSON responses.

- `charge-approved.json`, `charge-declined.json`, `refund-not-settled.json`, `void-approved.json`, `auth-failed.json`: the raw bodies, unchanged. The decline was triggered with billing ZIP 46282 (Authorize.Net's testing guide). A declined charge has `messages.resultCode` "Ok": only `transactionResponse.responseCode` (2) says it was declined.
- `merchant-details.json`, `merchant-details-no-client-key.json`: a recorded `getMerchantDetailsResponse` with the account's identifying values (merchant name, gateway ID, Public Client Key, business and contact details) replaced by made-up ones. The no-client-key variant has `publicClientKey` removed.
- Saved cards (CIM), recorded on 2026-10-07 with made-up customer IDs and emails, and the proxy's address in `customerIP` replaced by 203.0.113.9. Test cards: Visa 4111111111111111, Mastercard 5424000000000015 and Discover 6011000000000012.
  - Saving charges: `charge-approved-profile-created.json` is a charge with `createProfile`. `charge-approved-profile-duplicate.json` is the same customer charged again: E00039 inside `profileResponse`, while the charge itself is approved. `profile-from-transaction.json` is that card added to the existing profile.
  - Renewal charges of a payment profile: `saved-charge-approved.json` and `saved-charge-declined.json`. The declined one's payment profile has billing ZIP 46282.
  - Saving cards without a charge: `customer-profile-created.json`, `customer-profile-duplicate.json`, `payment-profile-created.json` (a liveMode $0 check with a full address), `payment-profile-duplicate.json` and `payment-profile-validation-failed.json` (liveMode without an address: E00027, reason code 290).
  - Deletes: `payment-profile-deleted.json`, and `record-not-found.json` for deleting it again.
  - Lookups: `transaction-details-captured.json`, plus `transaction-list-for-customer.json` and `transaction-list-empty.json`. The customer's transaction list can take a few seconds to show a new charge.
- `charge-held-for-review.json`, `refund-approved.json`: **not recordings.** A held charge needs fraud filters set up on the account, and a refund needs a charge that has settled (the sandbox settles once a day). They follow the documented format (`AnetApiSchema.xsd` in authorizenet/sdk-php and the API reference).

To refresh them, run the `sandbox` test suite with `AUTHORIZENET_PRINT_RESPONSES=1` (see `tests/Sandbox/README.md`) and copy the bodies it prints.
