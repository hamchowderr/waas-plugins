# Authorize.Net response fixtures

These are **not recordings from the Authorize.Net sandbox**. The sandbox (`apitest.authorize.net`) is blocked by the network policy of the environment these tests were written in.

They follow the documented response format: the element names and order of `createTransactionResponse`, `transactionResponse` and `getMerchantDetailsResponse` in `AnetApiSchema.xsd` (authorizenet/sdk-php), the JSON shapes in the API reference at developer.authorize.net, and the error codes it lists (2: declined, 54: refund of an unsettled charge, E00007: bad credentials). IDs and keys are made up. `charge-approved.json` starts with the UTF-8 byte order mark Authorize.Net puts on JSON responses.

Replace them with real sandbox responses once `apitest.authorize.net` is reachable: run the `sandbox` test suite (see `tests/Sandbox/README.md`) and copy the bodies it prints.
