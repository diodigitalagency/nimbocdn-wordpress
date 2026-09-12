# NimboCDN for WordPress

[![Plugin Check](https://github.com/diodigitalagency/nimbocdn-wordpress/actions/workflows/ci.yml/badge.svg)](https://github.com/diodigitalagency/nimbocdn-wordpress/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/diodigitalagency/nimbocdn-wordpress?label=release)](https://github.com/diodigitalagency/nimbocdn-wordpress/releases/latest)
[![License: GPL v2 or later](https://img.shields.io/badge/license-GPLv2%2B-blue.svg)](LICENSE)
[![WordPress 6.0+](https://img.shields.io/badge/WordPress-6.0%2B-21759b.svg)](https://wordpress.org/)
[![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://www.php.net/)

**Image CDN and image optimization for WordPress and WooCommerce.** NimboCDN resizes every image, converts it to WebP or AVIF and serves it from a global edge network — without touching a single original file, and without an account, an API key or a bulk compression job.

- **Website:** https://nimbocdn.net
- **Install page:** https://nimbocdn.net/install
- **Author:** [DIO Digital](https://www.diodigital.agency)
- **Support:** hello@nimbocdn.net

## How it works

1. **Install and activate.** The plugin registers your site with the service and measures your home page in the background. Nothing to configure.
2. **The plugin rewrites image URLs** in your HTML — `src`, `srcset`, `<picture>` sources, gallery zoom and lightbox links, CSS background images — so they point at the network instead of your server.
3. **The edge does the heavy lifting.** The first time a real visitor asks for an image, the network fetches your original once, resizes it, encodes it as AVIF, WebP, JPEG or PNG depending on the browser, and keeps the result in a permanent cache.

Your files are never modified, moved, re-compressed or deleted. Deactivate the plugin and your site serves its own images again, immediately. Uninstall it and not one option, transient or scheduled task is left behind.

## Free forever on your home page

The free plan is not a trial and does not expire: every image on your home page, in WebP, in three sizes, from the global cache, with up to 50 new home-page images per month. Visits are never counted or charged. Pro adds the whole site, AVIF and no image limit, at one fixed price with bandwidth included.

The complete description, FAQ and the disclosure of every network call the plugin makes are in [`readme.txt`](readme.txt) — the same file published on WordPress.org.

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.0 |
| PHP | 7.4 |
| WooCommerce | not required |

## Installation

**From WordPress.org** (once listed): *Plugins → Add New*, search for **NimboCDN**, install and activate.

**From a release:** download `nimbocdn-<version>.zip` from the [latest release](https://github.com/diodigitalagency/nimbocdn-wordpress/releases/latest) and upload it under *Plugins → Add New → Upload Plugin*. The same zip is offered at https://nimbocdn.net/install with its SHA-256.

**With WP-CLI:**

```sh
wp plugin install https://github.com/diodigitalagency/nimbocdn-wordpress/releases/latest/download/nimbocdn.zip --activate
```

## Design principles

These are the rules the code is written against. They are also what a reviewer should be able to verify by reading it.

- **No network call during a page view.** The rewriter reads options only. Availability is checked by WP-Cron and cached; a visitor never waits on the service being up.
- **The plugin never decides by plan.** It always rewrites; the edge decides what it transforms. There is no quota, licence or expiry logic in this code ([WordPress.org guideline 5](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#5-trialware-is-not-permitted)).
- **Three independent fallbacks**, all ending with the visitor seeing the image: the plugin stops rewriting when the service fails its health check; the edge redirects to the original when one image cannot be processed; the `<img>` falls back to the file WordPress would have served.
- **Nothing on the front end but attributes.** No JavaScript, no CSS. The only script the plugin loads is on its own settings screen, enqueued through the WordPress script queue.
- **Every input sanitized, every output escaped at the point of printing, every action behind a nonce *and* a capability check.**
- **Every option, transient and cron hook is prefixed `nimbocdn_`** and listed in one place (`Settings_Store`), so `uninstall.php` is exhaustive by construction.
- **The delivery hostname is never hardcoded.** It arrives from the service's health check, so a migration is a value pushed from the server, not a plugin release.

## Repository layout

```
nimbocdn.php                       Plugin bootstrap: header, autoloader, hooks
uninstall.php                      Removes every option, transient and scheduled task
includes/
  class-nimbocdn-activation.php    Registration and domain proof on activation
  class-nimbocdn-api.php           The only door to the control plane (wp_remote_*)
  class-nimbocdn-challenge.php     The one-time challenge the service reads to verify the domain
  class-nimbocdn-health.php        Twice-daily availability check, account sync
  class-nimbocdn-home.php          Which home-page images the free plan serves
  class-nimbocdn-probe.php         Measures the home page, file by file, in bounded slices
  class-nimbocdn-rewriter.php      Rewrites image URLs in attributes, srcset, <picture>, CSS
  class-nimbocdn-settings.php      The settings screen (Settings → NimboCDN)
  class-nimbocdn-settings-store.php Every option and transient the plugin owns
  class-nimbocdn-signer.php        HMAC signatures for delivery URLs
  data-network-pops.php            Coordinates of the network's data centers (settings map)
  data-world-dots.php              The dotted world map behind them
languages/                         .pot plus complete es_ES and pt_BR catalogues
readme.txt                         The WordPress.org readme
```

## Privacy and external services

The plugin is a connector for a hosted service operated by the plugin author. What is sent, when, and why — registration on activation, the twice-daily health check, image delivery, the network card on the settings screen, billing redirects — is disclosed in full in the **External services** section of [`readme.txt`](readme.txt). No personal data about visitors is collected.

- Terms of Service: https://nimbocdn.net/terms
- Privacy Policy: https://nimbocdn.net/privacy

## Development and releases

Development happens in DIO Digital's monorepo, where the plugin lives next to the edge and control-plane services it talks to and next to its test suite (24 scenarios run against a real WordPress). **This repository mirrors each release**: the exact package that is offered at nimbocdn.net/install, on the two reference stores it is verified on, and — once listed — on WordPress.org. Each release is a tag `vX.Y.Z` and a GitHub Release with the zip attached.

Every push runs the [official Plugin Check](https://wordpress.org/plugins/plugin-check/) and a PHP syntax check across the supported PHP versions ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)). The bar is zero errors and zero warnings.

Bug reports and feature requests are welcome as [issues](https://github.com/diodigitalagency/nimbocdn-wordpress/issues). See [CONTRIBUTING.md](CONTRIBUTING.md) for how changes flow back, and [SECURITY.md](SECURITY.md) for reporting vulnerabilities privately.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

GPL v2 or later. See [LICENSE](LICENSE). Icons in the settings screen are from [Tabler Icons](https://tabler.io/icons), MIT licensed (attribution in `readme.txt`).
