# Changelog

All notable changes to the NimboCDN WordPress plugin. This file is generated from the
`== Changelog ==` section of `readme.txt`, the one published on WordPress.org.

## 0.5.8
* The settings screen loads its stylesheet through the WordPress style queue instead of printing a `<style>` tag in the page.
* On WordPress 6.9 and later, image rewriting uses the template output buffer that WordPress itself opens and closes; the plugin no longer opens a buffer of its own. On earlier versions the buffer is closed explicitly at the end of the request.
* Translations are no longer bundled in the package; WordPress delivers them as language packs from translate.wordpress.org.
* Nothing assumes `/wp-content/uploads` any more: the verification file falls back to `content_url()`, and CSS background images are matched against the uploads path WordPress reports, so sites with a relocated content directory are covered.

## 0.5.7
* Crawlers no longer cost a new image copy. A client that accepts neither AVIF nor WebP now receives your original file, or a JPEG or PNG copy made earlier, instead of triggering a new JPEG. Measured on two stores from 10 to 14 September 2026: 71% of new copies were being made only for crawlers such as Amazonbot, AhrefsBot, bingbot and Googlebot-Image. Visitors whose browser accepts AVIF or WebP see no change.
* The plugin's author link now points to its author's page. No change to how images are rewritten.

## 0.5.6
* Release prepared for the WordPress.org plugin directory. The distributed package now carries no code comments at all, and every note WordPress requires — translator hints and licence attributions — is in English.
* Checkout, invoice and billing-portal redirects now go through `wp_safe_redirect()` with an explicit allow-list of destinations (our own service and the payment provider's hosted pages). A URL that does not qualify sends you back to the settings screen with a notice instead of anywhere else.
* The "we could not charge your card" notice registers its dismiss handler through the WordPress script queue instead of printing a `<script>` tag.
* The `Tested up to` field is declared only in the readme, as the directory asks.
* Code style aligned with the WordPress Coding Standards throughout; no behaviour change.

## 0.5.5
* Category banners and other full-width backgrounds are no longer blurry on desktop. A background image has no responsive set, so it was capped at 1200 px and stretched to fill a 1920 px screen. Backgrounds, the lightbox link and the product zoom now go up to 2048 px — the size WordPress already keeps on disk — when the original photo is that big.
* Nothing else grows. Catalogue grids, thumbnails and every responsive set stay at 400/800/1200: the larger step is only used where the visitor clicked or scrolled to see the picture big, never as a candidate the browser could pick for a small slot.

## 0.5.4
* If another plugin on your site already makes WebP copies and wraps your photos in a `<picture>` block, NimboCDN was doing nothing at all, and saying otherwise. It now finds the original photo behind their copy and serves that instead. Measured on a test page: 107 KB down to 39 KB.
* When the original cannot be found on disk, the whole block is left alone. Nothing is deleted, and the other plugin keeps working exactly as it did.
* Sites that serve different photos on phones and on desktop are untouched: a `<picture>` block that switches by screen size is left exactly as it was.

## 0.5.3
* Thumbnails now download at the size they are shown: lazy-loaded images that declare their dimensions let the browser pick the candidate for the space they occupy — the same rule WordPress applies to its own images since 6.7. Measured on a 28-image grid: 3.2 MB → 0.4 MB on desktop.

## 0.5.2
* The responsive set added in 0.5.1 now declares `sizes`, so a desktop browser no longer downloads a larger file than before.

## 0.5.1
* Photos that carried no responsive set now get one, so a phone downloads a phone-sized file instead of the one your desktop gets.

## 0.5.0
* The annual plan: Pro can be paid yearly for the price of ten months, with a one-click switch from monthly and a scheduled switch back.
* Prices in the panel now come from the service instead of being written into the plugin, so what you are shown is what you are charged.
* Fixed: a site that had not proved its domain yet was told "could not reach the billing service", which was false.

## 0.4.11
* The "Our global network" card times the response with the browser's network clock, over three measurements, and says so when it cannot reach the service.

## 0.4.10
* The plugin reports the site language on every panel sync, so service emails arrive in the language your WordPress uses.

## 0.4.6
* Spanish translation.

## 0.4.5
* A "Product emails" preference under Your account, which you can turn off from the panel or from the foot of any email. Payment notices are separate and always arrive.
* The settings screen in English, Spanish and Portuguese.

## 0.4.0
* The settings screen wears the brand and follows the admin colour scheme you picked in your profile.

## 0.3.0
* Activation works behind a firewall: the domain proof can be read from a static file in `uploads`, and a site that cannot be verified is still optimized on the free plan.
* CSS backgrounds, product gallery zoom and lightbox links are delivered optimized too.

## 0.2.0
* Settings screen rebuilt around what visitors actually downloaded, measured per request at the edge.
* Brazilian Portuguese translation.

## 0.1.0
* Initial release.

