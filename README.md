# Client Portal for WordPress — showcase

A white-label client portal for WordPress. Clients log in to a branded area on the agency's own site and see only their own projects, documents and invoices. No SaaS subscription, and client data stays on the site.

Built by [Outview](https://outview.co.za). Production version: **1.1.3**.

> **This repository is a view-only showcase, not the installable plugin.** It contains documentation and a few excerpts that show how the plugin is built. The full plugin is a commercial product. See [LICENSE.md](LICENSE.md).
>
> **Want this on your own site?** [Get a client portal set up by Outview](https://outview.co.za/client-portal/).

## What the plugin does

- **Client role:** a dedicated "Client" user role with no wp-admin access.
- **Projects:** status, progress bar, due date and description per project.
- **Documents:** per-client file downloads served through a protected handler, not guessable or shareable links.
- **Invoices:** amount, status, due date and an optional "Pay now" link (any payment URL: PayFast, a WooCommerce order-pay link, Stripe and so on).
- **One shortcode:** `[ov_client_portal]` renders the login form and the dashboard.
- **Per-site branding:** portal name, logo, accent colour and welcome message, so every install can look different with no code changes.
- **Admin preview:** administrators see all clients' content on the portal page, clearly labelled. Clients only ever see their own.
- **Safe uninstall:** deleting the plugin keeps the data unless data removal is explicitly opted into.

Requires WordPress 6.0+ and PHP 7.4+.

## What's in this repository

| Path | What it shows |
|---|---|
| `excerpts/class-ovcp-download-handler.php` | Protected downloads: expiring nonce links, login required, only the assigned client or an admin may download, anyone else gets a 403, and a lapsed license blocks direct links too. |
| `excerpts/class-ovcp-client-restrictions.php` | Keeping clients out of wp-admin: redirect, hidden admin bar, and login redirect to the portal page. |
| `excerpts/class-ovcp-roles.php` | The dedicated Client role. |
| `excerpts/class-ovcp-license.php` | Domain-locked licensing with a periodic check-in and a grace period (see below). |

The rest of the plugin (post types, admin screens, the portal dashboard and its styling, settings, and the plugin bootstrap) is deliberately not included. These excerpts depend on that code and will not run on their own.

## Licensing design

The production plugin requires a license key from Outview:

- The key is bound to the domain it was first activated on.
- The plugin checks it with Outview's licensing server about twice a day, sending only the license key, the site domain and URL, a product identifier and the plugin version. No client, project or invoice data is ever sent.
- If the licensing server can't be reached, there is a 14-day grace period.
- If the license isn't active, visitors who aren't administrators see a "temporarily unavailable" notice. Administrators are never locked out, so the license can always be fixed or renewed.

A companion showcase of the server side is at [outview-licensing](https://github.com/henfredpietersen/outview-licensing).

## Changelog (production)

- **1.1.3:** Corrected the bundled readme, which wrongly said the plugin had no license key.
- **1.1.2:** Fixed unreadable text on dark site themes; the portal now has its own light surface and WCAG AA text contrast.
- **1.1.1:** Fixed license activation, which called a licensing endpoint that did not exist.
- **1.1.0:** Domain-locked license with periodic check-in.
- **1.0.x:** Initial release.

## Contact

[outview.co.za/client-portal](https://outview.co.za/client-portal/)
