## What this changes

<!-- One paragraph. What a store owner would notice, and why. -->

## How it was verified

- [ ] `php -l` passes on PHP 7.4 – 8.4
- [ ] Plugin Check: zero errors, zero warnings (`wp plugin check nimbocdn --include-experimental`)
- [ ] Inputs sanitized, outputs escaped at the point of printing, actions behind nonce **and** capability
- [ ] No plan/quota/billing logic in the rewriter; no network call added to a page view
- [ ] New options/transients/cron hooks are prefixed `nimbocdn_` and listed in `Settings_Store`
- [ ] Strings in English with the `nimbocdn` text domain and `translators:` comments where needed

## Measured on

<!-- Site, page, before/after bytes or a screenshot of the settings screen. Numbers beat adjectives. -->
