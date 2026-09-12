# Contributing

Thank you for taking the time. This repository is the public mirror of the NimboCDN WordPress plugin: every tag here is a release that is also offered at https://nimbocdn.net/install and — once listed — on WordPress.org.

## Reporting a bug

Open an [issue](https://github.com/diodigitalagency/nimbocdn-wordpress/issues/new/choose) using the bug template. The most useful report contains:

- the plugin version (shown on the plugins list, and pre-filled by the **Support** link there),
- WordPress and PHP versions, and whether WooCommerce is active,
- the page where it happens and one image URL from that page, before and after the plugin rewrote it,
- what you expected and what you saw.

Please do **not** report security issues in a public issue. See [SECURITY.md](SECURITY.md).

## Proposing a change

Development happens in a monorepo where the plugin lives next to the edge and control-plane services it talks to and next to its test suite. Pull requests here are welcome and are reviewed like any other change; when accepted, the change is applied upstream, released, and mirrored back here with credit in the changelog. A pull request against this repository is therefore the right way to propose a fix — it just does not merge here directly.

Before opening one, please make sure that:

1. `php -l` passes on every file for PHP 7.4 through 8.4.
2. The [official Plugin Check](https://wordpress.org/plugins/plugin-check/) reports **zero errors and zero warnings** (`wp plugin check nimbocdn --include-experimental`). CI runs both on every push.
3. Every input is sanitized, every output is escaped where it is printed, and every admin action checks a nonce **and** a capability, as two independent checks.
4. Nothing in the rewriter reads the plan, the quota or anything about billing. The plugin always rewrites; the edge decides what it serves.
5. No network call is added to a front-end page view.
6. New options, transients or cron hooks are prefixed `nimbocdn_` and added to `Settings_Store`, so `uninstall.php` keeps removing everything.
7. User-facing strings are in English, wrapped in the `nimbocdn` text domain, with a `translators:` comment wherever there is a placeholder.

## Translations

The plugin ships complete Spanish (`es_ES`) and Brazilian Portuguese (`pt_BR`) catalogues. Once the plugin is listed on WordPress.org, translations go through [translate.wordpress.org](https://translate.wordpress.org/); until then, corrections are welcome as issues or pull requests against `languages/`.

## Code of conduct

This project follows the [Contributor Covenant](CODE_OF_CONDUCT.md).
