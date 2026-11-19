# Example bank connector (synthetic only)

This is a separate Akaunting module containing exactly one service provider
and one connector. It registers `examplebank` with the installed Banking Connectors
registry and requires no changes to the Banking Connectors core.

The fixture uses no external network and refuses to run outside `APP_ENV=local`.
It is **not a real bank implementation** and must never be used for real
financial synchronization. Replace its client/statement mapping with an
institution-specific, independently tested read-only implementation.

Copy the module under `modules/BankConnectorExample`, register with
`php artisan module:install bank-connector-example <companyId> en-GB`, and
verify that the provider appears in Banking Connectors's bank connection selector.
