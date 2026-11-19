# Git-native Banking Connectors deployment

Banking Connectors is a normal, independently versioned Git repository.
It does not need a bundled tarball, a proprietary Akaunting app marketplace
or changes to Akaunting core source files.

## Repositories and module layout

- LibreCodeCoop/akaunting-credential-vault -> modules/CredentialVault
- LibreCodeCoop/banking-connectors -> modules/BankingConnectors
- LibreCodeCoop/akaunting-nfse -> modules/Nfse (optional)

GitHub source repositories must contain approved commits before cloning.
The helper lives in the CredentialVault repository and runs after checkout.

From the **existing** Akaunting application root, on the host:

    git clone https://github.com/LibreCodeCoop/akaunting-credential-vault.git modules/CredentialVault
    git clone https://github.com/LibreCodeCoop/banking-connectors.git modules/BankingConnectors

To deploy NFS-e as well:

    git clone https://github.com/LibreCodeCoop/akaunting-nfse.git modules/Nfse
    cd modules/Nfse
    composer thirdparty:build:prod

Use the standard native Akaunting command to view the registration plan
after the Git checkouts are available:

    php artisan credential-vault:install-stack 2 --with=banking-connectors

For an additional, source-aware preflight, from the Akaunting root:

    bash modules/CredentialVault/scripts/post-git-checkout.sh \
      --app-root /path/to/akaunting --company 2 --with banking-connectors

This helper refuses dirty Git directories, unauthorized origins, mismatched
module aliases, missing scoped NFS-e dependencies and unknown application
environments. Its default mode changes nothing.

Only after reviewing the plan and authorizing the environment:

    CREDENTIAL_VAULT_ALLOW_MODULE_INSTALL=yes \
      bash modules/CredentialVault/scripts/post-git-checkout.sh \
        --app-root /path/to/akaunting --company 2 \
        --with banking-connectors --apply

The native installer handles per-company registration and targeted module
migrations. Production has a further explicit approval gate documented in
CredentialVault/docs/INSTALLATION.md. A live production deployment additionally
requires reviewed backups, DB compatibility checks and a maintenance window;
no script can guarantee that financial data will survive an unreviewed migration.

## Update by Git SHA

Check existing module revisions, fetch tags and checkout an approved,
verified commit. Examples:

    git -C modules/BankingConnectors status --short
    git -C modules/BankingConnectors fetch --tags origin
    git -C modules/BankingConnectors checkout --detach <approved-commit-sha>

Repeat for CredentialVault or NFS-e as appropriate. Rebuild the scoped
NFS-e third-party runtime after any applicable change, then rerun the
preflight and the approved post-checkout steps. Never hide a dirty tree with
git reset --hard or overwrite private credential files.

## After registration

Validate the route and the read-only health command:

    php artisan route:list --path=banking-connectors
    php artisan banking-connectors:health --strict

No Sicoob request is made by health. Credentials must be provisioned
separately through company-scoped OpenBao AppRoles and verified private
certificate paths. Sicoob polling remains disabled until enabled
explicitly; it stages bank statements but does not automatically create,
alter or settle native Akaunting financial transactions.

See OPENBAO.md and OBSERVABILITY.md for the credential and monitoring
contracts, and CONNECTORS.md for adding new financial institutions without
editing Banking Connectors core.
