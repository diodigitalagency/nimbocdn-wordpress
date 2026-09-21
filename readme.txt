=== NimboCDN – Image Optimization & Image CDN | Convert WebP & AVIF ===
Contributors: mandrakecrm
Tags: image optimization, optimize images, webp, avif, image cdn
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Image CDN for WordPress and WooCommerce: a faster site, with your images in the most modern, most efficient format — WebP or AVIF.

== Description ==

**NimboCDN is an image CDN for WordPress and WooCommerce. Every image on your site is resized and converted to WebP or AVIF — the most modern and efficient image formats there are, with no loss in perceived quality — and delivered from our global network, in 335 cities. Your own server stops delivering images and your site gets faster. You do not need to create an account or paste an API key, and there is no bulk compression process to run on your server. We never modify your original files.**

You upload a large image and WordPress keeps the file exactly as it came. That is the file your visitor's browser downloads — whole, even when the image shows up small on a phone. Your visitor waits for weight they never get to see, and that weight leaves from your server: every image, to every visitor, every single time.

**NimboCDN takes care of that on its own.** Every visitor receives each image in the size their screen needs and the best format their browser accepts. You configure nothing.

= 👀 What NimboCDN does, at a glance =

* **Optimize images automatically** — no bulk job, no queue, nothing to click
* **Resize images** to the width the screen needs, with a matching `srcset`
* **Convert to WebP and AVIF**, chosen per visitor from what their browser accepts
* **Serve from a global image CDN** — 335 cities in more than 125 countries
* **Delegate image delivery to our network**, so your server can get on with your blog or shop
* **Leave every original file untouched**, and undo the whole thing in one click
* **No account, no API key, no credit card** — install, activate, done

= ⚙️ How NimboCDN works =

1. **Install and activate.** The plugin registers your site and measures your home page in the background. Nothing to configure, no key to paste.
2. **The plugin rewrites your image URLs.** In your HTML it updates `src`, `srcset`, `<picture>` sources, gallery zoom and lightbox links and CSS backgrounds, so they point at our network. Nothing else changes.
3. **Our network does the heavy lifting.** The first time a real visitor asks for an image, the network fetches your original once, resizes it, encodes it as AVIF or WebP for that browser and keeps the result in a permanent cache. From then on your server never sees that request again.

= 🚀 Your server stops delivering images =

On a normal WordPress site, serving images is the bulk of the work your server does: on a page with twenty images there are twenty files to find on disk, read and push out, for every visitor, all day. That work competes with what you actually care about — building the page, running the queries, taking the order.

With NimboCDN your server delegates that job. It is asked once per image, ever; after that our network answers. **Measured on real traffic: 98.7% of the images visitors received never came out of the site's own server.** What is left is what your server is for — showing your blog, your shop, your pages.

= 🌎 An image CDN, not an image compressor =

Most plugins that optimize images for WordPress are library compressors: an image optimizer that works on the files you already uploaded. They re-compress what sits on your server and charge you for what you **upload** — and since WordPress makes six to eight thumbnails per upload, you pay for sizes no visitor will ever request.

NimboCDN works at the other end, on what actually reaches the visitor:

* An image is processed **once**, the first time a real visitor asks for it. No bulk optimization to sit through.
* **Traffic is unlimited on every plan.** No bandwidth charge, no per-visit charge, no overage.
* **One click to undo.** Deactivate the plugin and your site serves its own images again, immediately.

= 🔄 Convert images to WebP and AVIF, automatically =

Every image is delivered as AVIF or WebP depending on what the visitor's browser accepts — no converter to run, no second copy on your server. A client that accepts neither gets your original file, exactly as without the plugin.

= 📐 Properly size images for every screen =

A phone does not need a 3000-pixel file. You never have to resize images by hand or regenerate thumbnails again: NimboCDN offers each image at 400, 800 and 1200 pixels wide with a matching `sizes` attribute and lets the browser pick. No width larger than the one WordPress offered is ever announced, so a page can never get heavier than it was.

This is exactly what PageSpeed Insights asks for when it reports *serve images in next-gen formats* and *properly size images*.

= 📁 We never modify your original files =

Your JPEG and PNG files stay exactly as you uploaded them: not re-compressed, not resized, not moved, not deleted. The plugin only changes the address of the image in your HTML.

= 🎁 Free forever on your home page =

The free plan is not a trial and does not expire. It serves **every image on your home page** in WebP, in three sizes, from the global cache, with up to **50 new home-page images per month** — the number is here, before you install, on purpose. The rest of your site keeps being served by WordPress exactly as before, and visits are never counted or charged: ten visitors or ten thousand, it stays free.

= 👑 What the Pro plan adds =

* **Your whole site**: catalogue, product pages, blog and pages — not just the home page
* **AVIF**, about 21% lighter than WebP on the same image
* **No image limit**
* One fixed price, monthly or annual, with bandwidth included

Pro is a paid plan of the hosted service — there is no second plugin to install. You switch plans from this same settings screen, and all the plugin's code is here and works on every plan; the plan only changes what the network serves.

= 🛒 Built for WooCommerce, works on any WordPress site =

NimboCDN was built for WooCommerce stores and tested on them: product galleries, gallery zoom (`data-large_image`), lightboxes, category banners and CSS background heroes. A catalogue is where image weight hurts most — one product page can carry five images, a category page forty.

It works just as well on a blog, a portfolio or a company site, and is at its most useful on shared hosting, where delegating image delivery is the cheapest speed there is. WooCommerce is not required.

= 🧩 Works with your caching plugin, your page builder and your theme =

* **Caching plugins.** The plugin only rewrites image attributes, and cached HTML stays valid: old delivery hostnames keep working indefinitely, by design.
* **Page builders and themes.** Elementor, Divi, Gutenberg blocks, galleries and sliders all produce the same HTML, which is where the plugin works.
* **Existing WebP plugins.** If another plugin wraps your images in a `<picture>` block with its own WebP copies, NimboCDN finds the original behind it and serves that.
* **Lazy loading.** No JavaScript is added to your pages, and your theme's lazy loading is untouched.

= 🛡️ If anything goes wrong =

Three independent fallbacks, each ending with the visitor seeing your image:

1. If the service stops responding, the plugin stops rewriting URLs and WordPress serves its own images.
2. If a single image cannot be processed, the edge redirects to your original.
3. If even that fails, the `<img>` element falls back to the file WordPress would have served.

The plugin makes no network call during a page view, so it cannot become a single point of failure.

= 🔒 Privacy in one paragraph =

No personal data about your visitors is collected — not their IP address, not their browser, not the page they viewed. The service keeps aggregate counts and byte totals per site, so the settings screen can show what your visitors stopped downloading. Everything the plugin sends is listed under *External services* below. https://nimbocdn.net/privacy

= 👉 Try it on your home page, free =

**Install NimboCDN, activate it, and open Settings → NimboCDN.** Your home page is where that weight hurts most — it is the page most visitors see first — so that is the one we weigh, right there, before you decide anything. No account, no card, one click to undo.

== External services ==

This plugin is a connector for **NimboCDN**, a hosted image delivery service operated by DIO Digital, the plugin author. The plugin does nothing useful without it: every image it rewrites is served by the service. By installing and activating the plugin you register your site with the service, as described here.

* Service: https://nimbocdn.net
* Terms of Service: https://nimbocdn.net/terms
* Privacy Policy: https://nimbocdn.net/privacy

**Registration — on activation, once.** The plugin sends your site's domain to `https://nimbocdn-prd-control.nimbocdn.workers.dev/activate/start`, which answers with a random challenge. The plugin exposes that challenge for five minutes at `/wp-json/nimbocdn/v1/challenge`, at `/?nimbocdn-challenge=1`, and as a small text file `wp-content/uploads/nimbocdn-challenge-<random>.txt` with a blank PNG image of the same name next to it (both removed as soon as registration finishes). It then sends the domain, site name, WordPress version, plugin version, site language and the administrator email address to `/activate/finish`. The service reads the challenge from your site — proof that whoever registers the domain controls it. When a firewall or antibots answers the service with a challenge page of its own, the service checks instead the width and height of that PNG image, fetched through Cloudflare Images, which are derived from the challenge. A domain nobody registered before is registered even when the proof fails, so a firewall never leaves the plugin idle; until the proof succeeds the site stays on the free plan and cannot manage billing. Re-activating the same domain returns the same credentials, so your history survives an uninstall; `www.` and non-`www.` spellings of one domain are one site.

**Health, account and home-page sync — from the background and from the settings screen.** Twice a day by WP-Cron, when you press *Measure now* (at most once a minute), when you open or return to the settings screen, and when your home page changes, the plugin sends your site identifier to `/health`, `/account` and `/site/home` on the same host. The last one carries the list of image files your home page uses and the HTML of your home page as your own server returns it, so the service can verify them — by visiting your home page, or, when a firewall or antibots blocks that visit, against the HTML the plugin sent — and answer which ones it will serve. The response says whether the service is available, which delivery hostname to use, your plan and allowance, and the delivery statistics shown on the settings screen. No visitor data and nothing about your posts other than your public home page is sent.

**Image delivery — when a visitor loads a page.** The visitor's browser requests images from the delivery hostname (currently `cdn.nimbocdn.net`). That request carries the URL of the original image, the size of the file WordPress would otherwise have served, and the browser's own `Accept` header, as any image request does. For each image served, the edge records the delivered size, format and cache state against your site identifier — sampled on busy sites — never the visitor's IP address, user agent or page. When a request is refused (for example, a signature that does not match), the edge also records the URL of the refused original so the refusal can be investigated; that URL is a file path on your own site. The plugin itself makes no network call during a page view.

**Network card — when you open the settings screen.** Your browser asks `https://<delivery host>/whoami` four times: the first request only opens the connection, the next three are timed and the fastest is shown. A failed request is retried once. The answer names the data center that served the request and the approximate city of your own IP address, so the screen can show where your images are delivered from, and in how many milliseconds, for that computer. Nothing is stored.

**Billing — only when you click.** *Get Pro*, *Manage plan* and *Switch to annual* ask the service for a checkout, portal or invoice URL and send your browser there. Payments are handled by the service's payment provider on its own pages; the plugin never sees a card number, a price or a currency, and contains no payment logic.

**Emails.** Two kinds, and they are not the same thing.

* **Account emails** — that your site was registered, that a card was declined, a receipt. They are part of the service and always arrive, because a plan lost in silence is a plan you find out about when someone notices the site is slow.
* **Product emails** — occasional notes about NimboCDN: new features and tips. Never more than a couple a month. **You can switch them off** under *Your account* on the settings screen, or from the link at the foot of any of them; doing so never affects the account emails above.

**What is stored, and how to erase it.** The service keeps your domain, the administrator email, your plan and aggregate delivery statistics. We never sell or share your data with third parties; your address is used by us, to write to you, and by nobody else. Uninstalling the plugin removes every option, transient and scheduled task it created on your site. To erase your account and statistics from the service as well, write to hello@nimbocdn.net from the registered address.

== Credits ==

The icons in the settings screen — the crown on the Pro badge, the handshake on a plan provided by a partner, and the small line icons beside the "Why NimboCDN" and support text — are from **Tabler Icons**, MIT licensed.

Copyright (c) 2020-2026 Paweł Kuna — https://tabler.io/icons

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the above copyright notice and this permission notice being included in all copies or substantial portions of the Software.

The SVG paths are inlined in the settings screen rather than shipped as files: there are a handful of them, and an `<img>` would cost a request per page load for a few hundred bytes of path.

== Installation ==

1. In your WordPress dashboard go to **Plugins → Add New**, search for **NimboCDN**, then click **Install Now** and **Activate**. Or upload the zip under **Plugins → Add New → Upload Plugin**.
2. That is all. Activation registers your site and images begin being delivered from the edge.

Activation takes you to **Settings → NimboCDN**, where the home page is measured right away and the visitor statistics fill in as real traffic arrives. You can return there any time from that menu, or through the **Settings** link on the plugins list.

No account, no API key and no bulk optimization step are needed. WooCommerce is not required.

== Frequently Asked Questions ==

= Do I need an account or an API key? =

No. Activation registers your site automatically and proves that you control the domain. There is nothing to sign up for and nothing to paste. Your credentials are stored in your WordPress options and travel with your site.

= Does this change my original images? =

No. Nothing in your media library is modified, re-compressed, moved or deleted. The plugin only changes the address of the image in your HTML, so it points to our global network instead of your server.

= Is NimboCDN a content delivery network (CDN) for images? =

Yes, and only for images. Your pages, your CSS and your JavaScript keep being served by your own hosting exactly as they are today; only the images are answered by our network, from a point near each visitor. It is not a WebP converter that leaves a second copy of every file on your disk: each image is prepared the first time a real visitor asks for it, and nothing new is written to your server.

= What exactly does the free plan include? =

Every image on your home page, delivered in WebP at 400, 800 and 1200 pixels wide, from a permanent global cache, with up to 50 **new** home-page images per month. The allowance renews on the 1st. Traffic and visits are unlimited. No credit card.

= Is there a catch with the free plan? =

No. It is not a trial and it does not expire. Visits cost us almost nothing, so we do not charge for them. The limit is on new home-page images, not on how many people see them. If the rest of your site needs optimizing too, that is what Pro is for.

= Will I get a surprise bill? =

No. Pro is one fixed price with bandwidth included: no charge per visit, per gigabyte, or for going over. If a payment fails there are three days of grace and then the site falls back to the free plan — your images never break.

= What does the Pro plan add? =

Your whole site instead of just the home page — catalogue, product pages, blog and pages — in AVIF, which is about 21% lighter than WebP on the same image, with no image limit. Monthly or annual billing.

= Do I have to run a bulk optimization? =

No, and there is nothing to sit through. Each image is prepared the first time a real visitor asks for it. Your home page is measured as soon as you activate, so you see a result within minutes rather than after a batch job.

= How do I fix "serve images in next-gen formats" in PageSpeed Insights? =

That warning means your images are being sent as JPEG or PNG to browsers that accept better formats. NimboCDN answers each request with AVIF or WebP when the browser's `Accept` header says it supports them, and with your original file when it does not.

= How do I fix "properly size images"? =

That warning means the file is bigger than the space it is displayed in. NimboCDN offers three widths — 400, 800 and 1200 pixels — with a matching `sizes` attribute, and lets the browser pick. It never offers a width larger than the one WordPress already offered, so a page cannot get heavier than it was.

= Does it handle responsive images and `srcset`? =

Yes. NimboCDN offers each image at 400, 800 and 1200 pixels wide and writes the matching `srcset` and `sizes`, so the browser downloads the width it is actually going to show. An image that arrived without a responsive set gets one; an image that already had one keeps it, and no width larger than the one WordPress already offered is ever announced.

= Does this make my site faster? =

Yes, on the part that depends on images — which is usually the biggest part. Your visitor downloads far fewer bytes and gets them from a point near them, so the images appear sooner, and how fast your page appears is what Google measures. We can tell you how many bytes your visitors stopped downloading; we cannot promise a score or a position in search, because those also depend on your hosting, your theme and everything else on the page.

= Does NimboCDN help with Core Web Vitals and LCP? =

Yes, and on most sites that is where the biggest gain is. Largest Contentful Paint — LCP — is how long your page takes to show its main piece of content, and on most WordPress sites that piece is an image: exactly what NimboCDN makes lighter and delivers from a point near the visitor. On one real home page, weighed file by file, it came out 88% lighter at the same quality level, and a lighter image appears sooner. What we do not put a number on is your final score, because your hosting, your theme and the rest of the page count too — but on most sites the images are what your visitor is waiting for, and that is the part we take care of.

= Does it work with JPEG and PNG, and with transparency? =

Yes. JPEG and PNG originals are both delivered optimized, as AVIF or WebP. Both formats keep transparency, so it is never lost; a client that accepts neither receives your original file, transparency included.

= I already use an image compression plugin. Do I need to remove it? =

No. A compressor works on the files in your library; NimboCDN works on what reaches the visitor. If the other plugin wraps your images in a `<picture>` block with its own WebP copies, NimboCDN finds your original behind that copy and serves it, so nothing is compressed twice.

= Will it conflict with my caching plugin? =

It should not. The plugin only rewrites image attributes, and cached HTML containing rewritten URLs stays valid — old delivery hostnames keep working indefinitely, by design. Rewriting the same HTML twice never produces nested URLs.

= Does it add JavaScript or CSS to my pages? =

No. On the front end the plugin changes image attributes and nothing else. The only script it loads is on its own settings screen.

= What happens if I deactivate or uninstall it? =

Your site immediately serves its own images again — they were never altered. Uninstalling additionally removes every option, transient and scheduled task the plugin created, leaving no rows behind. Your delivery statistics are kept by the service under your domain, so reinstalling shows them again; write to hello@nimbocdn.net to have them erased.

= What if the service goes down? =

Your site keeps working with its own images, exactly as before. The plugin stops rewriting on its own after three failed checks and resumes when the service answers again. NimboCDN is not a single point of failure for your site.

= Does it work without WooCommerce? =

Yes. WooCommerce stores are what it was built for and tested against, but it optimizes images on any WordPress site.

= Do you store data about my visitors? =

No. Not their IP address, not their browser, not the page they viewed. The service counts bytes and formats per site and nothing else, and the plugin makes no network calls during a page view. The full disclosure is in the External services section above.

= Is it compatible with GDPR and LGPD? =

The plugin collects no personal data about your visitors, so there is nothing about them to disclose or erase. The only personal data the service holds is the administrator email address used for account notices; you can change it from the settings screen and have it erased by writing to hello@nimbocdn.net. Details: https://nimbocdn.net/privacy

= What do the numbers on the settings screen mean? =

Every number names what it was measured on. "Your visitors downloaded X less" is the sum, over every image served in the last 30 days, of the difference between what WordPress would have sent and what NimboCDN sent. It comes from sampled edge analytics, so it is labelled *estimated*. "Your home page" is weighed file by file and is labelled *measured*.

= Which languages does it come in? =

English. Translations are delivered by WordPress itself as language packs from translate.wordpress.org, where our Brazilian Portuguese and Spanish translations are published. Languages without a translation fall back to English.

= Where do I get support? =

Through the plugin's support forum on WordPress.org, or by email at hello@nimbocdn.net. The **Support** link on the plugins list opens a message with your plugin version already filled in.

== Screenshots ==

1. An image CDN for WordPress: every image reaches the visitor resized and in WebP or AVIF, from our global network — not from your server.
2. Measured, not promised: one real home page weighed file by file, 88% lighter at the same quality level.
3. Your original JPEG and PNG files are never modified, moved or deleted. Deactivate and everything goes back, instantly.
4. The settings screen on Pro: what your visitors actually downloaded, measured at the edge. Settings → NimboCDN.
5. Install, activate, done — no account, no API key, and no bulk compression process to sit through.
6. The free plan: your home page optimized forever, and it says plainly which images WordPress is still serving.

== Changelog ==

= 1.0.0 =
* First stable release. Nothing changes in how your images are delivered: the version number catches up with a plugin that is already serving real stores, and that is now published in the WordPress.org plugin directory.
* Three answers added to the FAQ, for three questions the listing did not answer: whether NimboCDN is a content delivery network for images, how it handles responsive images and `srcset`, and what it does for Core Web Vitals and LCP.

= 0.5.12 =
* The package downloaded from nimbocdn.net now carries the Spanish and Portuguese translations, so the settings screen is in your language the moment you activate it. Sites installed from the WordPress.org directory are unaffected: they keep getting translations as language packs, which always take precedence over any bundled file.

= 0.5.11 =
* The settings screen no longer runs the free and Pro plan labels into their own text in Spanish and Portuguese. The label column was a fixed width sized for the English "Free"; "Gratuito" is twice as long and touched its description with no gap. Found on 20 September 2026, rendering the screen in all three languages for the directory screenshots.
* Directory listing rewritten: what NimboCDN is and what it does, in English, Spanish and Portuguese. Nothing in the plugin's behaviour changes.

= 0.5.10 =
* When your domain is verified, the plugin empties your page cache so cached pages come back with the new image addresses. Measured on a store running WP Rocket on 19 September 2026: after verification, 3,393 cached pages kept the old addresses and loaded the original images. Supported: WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround Speed Optimizer, Cache Enabler, Hummingbird, WP-Optimize, Comet Cache, Swift Performance, Proxy Cache Purge, Cloudflare, and the Kinsta, WP Engine, Pantheon, GoDaddy and Pressable host caches.
* Our network also keeps accepting the previous addresses for 30 days, so any cache the plugin cannot reach keeps serving optimized images.

= 0.5.9 =
* Works behind a hosting antibots (SiteGround and similar) with nothing for you to do. Measured on a SiteGround store on 19 September 2026: the domain could not be verified, so upgrading and billing were locked, and the free plan admitted 0 of the 72 images on the home page. With this version the domain was verified in 3 seconds and the home page images were admitted, without touching the firewall.
* Domain verification writes a blank PNG image next to the verification file and removes both when it finishes. The service reads its size through Cloudflare Images, which the antibots let through.
* Home page sync sends the HTML of your home page, read by your own server, so the service can check the images even when its own visit is blocked.
* The settings screen no longer asks you to allow a user agent in your firewall.

= 0.5.8 =
* The settings screen loads its stylesheet through the WordPress style queue instead of printing a `<style>` tag in the page.
* On WordPress 6.9 and later, image rewriting uses the template output buffer that WordPress itself opens and closes; the plugin no longer opens a buffer of its own. On earlier versions the buffer is closed explicitly at the end of the request.
* Translations are no longer bundled in the package; WordPress delivers them as language packs from translate.wordpress.org.
* Nothing assumes `/wp-content/uploads` any more: the verification file falls back to `content_url()`, and CSS background images are matched against the uploads path WordPress reports, so sites with a relocated content directory are covered.

= 0.5.7 =
* Crawlers no longer cost a new image copy. A client that accepts neither AVIF nor WebP now receives your original file, or a JPEG or PNG copy made earlier, instead of triggering a new JPEG. Measured on two stores from 10 to 14 September 2026: 71% of new copies were being made only for crawlers such as Amazonbot, AhrefsBot, bingbot and Googlebot-Image. Visitors whose browser accepts AVIF or WebP see no change.
* The plugin's author link now points to its author's page. No change to how images are rewritten.

= 0.5.6 =
* Release prepared for the WordPress.org plugin directory. The distributed package now carries no code comments at all, and every note WordPress requires — translator hints and licence attributions — is in English.
* Checkout, invoice and billing-portal redirects now go through `wp_safe_redirect()` with an explicit allow-list of destinations (our own service and the payment provider's hosted pages). A URL that does not qualify sends you back to the settings screen with a notice instead of anywhere else.
* The "we could not charge your card" notice registers its dismiss handler through the WordPress script queue instead of printing a `<script>` tag.
* The `Tested up to` field is declared only in the readme, as the directory asks.
* Code style aligned with the WordPress Coding Standards throughout; no behaviour change.

= 0.5.5 =
* Category banners and other full-width backgrounds are no longer blurry on desktop. A background image has no responsive set, so it was capped at 1200 px and stretched to fill a 1920 px screen. Backgrounds, the lightbox link and the product zoom now go up to 2048 px — the size WordPress already keeps on disk — when the original photo is that big.
* Nothing else grows. Catalogue grids, thumbnails and every responsive set stay at 400/800/1200: the larger step is only used where the visitor clicked or scrolled to see the picture big, never as a candidate the browser could pick for a small slot.

= 0.5.4 =
* If another plugin on your site already makes WebP copies and wraps your photos in a `<picture>` block, NimboCDN was doing nothing at all, and saying otherwise. It now finds the original photo behind their copy and serves that instead. Measured on a test page: 107 KB down to 39 KB.
* When the original cannot be found on disk, the whole block is left alone. Nothing is deleted, and the other plugin keeps working exactly as it did.
* Sites that serve different photos on phones and on desktop are untouched: a `<picture>` block that switches by screen size is left exactly as it was.

= 0.5.3 =
* Thumbnails now download at the size they are shown: lazy-loaded images that declare their dimensions let the browser pick the candidate for the space they occupy — the same rule WordPress applies to its own images since 6.7. Measured on a 28-image grid: 3.2 MB → 0.4 MB on desktop.

= 0.5.2 =
* The responsive set added in 0.5.1 now declares `sizes`, so a desktop browser no longer downloads a larger file than before.

= 0.5.1 =
* Photos that carried no responsive set now get one, so a phone downloads a phone-sized file instead of the one your desktop gets.

= 0.5.0 =
* The annual plan: Pro can be paid yearly for the price of ten months, with a one-click switch from monthly and a scheduled switch back.
* Prices in the panel now come from the service instead of being written into the plugin, so what you are shown is what you are charged.
* Fixed: a site that had not proved its domain yet was told "could not reach the billing service", which was false.

= 0.4.11 =
* The "Our global network" card times the response with the browser's network clock, over three measurements, and says so when it cannot reach the service.

= 0.4.10 =
* The plugin reports the site language on every panel sync, so service emails arrive in the language your WordPress uses.

= 0.4.6 =
* Spanish translation.

= 0.4.5 =
* A "Product emails" preference under Your account, which you can turn off from the panel or from the foot of any email. Payment notices are separate and always arrive.
* The settings screen in English, Spanish and Portuguese.

= 0.4.0 =
* The settings screen wears the brand and follows the admin colour scheme you picked in your profile.

= 0.3.0 =
* Activation works behind a firewall: the domain proof can be read from a static file in `uploads`, and a site that cannot be verified is still optimized on the free plan.
* CSS backgrounds, product gallery zoom and lightbox links are delivered optimized too.

= 0.2.0 =
* Settings screen rebuilt around what visitors actually downloaded, measured per request at the edge.
* Brazilian Portuguese translation.

= 0.1.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
First stable release. No change to how your images are delivered. Update at any time.

= 0.5.12 =
The copy downloaded from nimbocdn.net now shows the settings screen in Spanish and Portuguese without waiting for a language pack. Update at any time.

= 0.5.11 =
Fixes the plan labels overlapping their own text on the settings screen in Spanish and Portuguese. Update at any time.

= 0.5.10 =
After your domain is verified, cached pages are refreshed so every image keeps being optimized. Update at any time.

= 0.5.9 =
Sites behind a hosting antibots (SiteGround and similar) now verify their domain and sync their home page with nothing to configure. Update at any time.

= 0.5.7 =
Documents a delivery change on our network: clients that accept neither AVIF nor WebP, almost always crawlers, now get your original file. Nothing changes in how the plugin rewrites your images. Update at any time.

= 0.5.6 =
Safer billing redirects, a cleaner package prepared for the WordPress.org directory, and no change to how your images are delivered. Update at any time.
