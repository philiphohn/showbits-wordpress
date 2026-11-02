=== ShowBits ===
Contributors: showbits
Tags: video, media library, clips, podcast, editorial
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import released clips and quote cards from your ShowBits workspace into the WordPress media library.

== Description ==

[ShowBits](https://showbits.ai) turns podcast and TV episodes into short clips, subtitles and quote cards, and your editors approve them there. This plugin connects your WordPress site to a ShowBits workspace and brings what your editors released into the media library, ready for posts and pages.

**You need a ShowBits workspace to use this plugin** (ShowBits Cloud on any plan, or a ShowBits OnPremise installation). The plugin is the connector; the clips and quote cards come from ShowBits. See "External services" below for what is exchanged with ShowBits.

= What it does =

* **Media → ShowBits** lists the released clips and quote cards of the connected workspace, newest first, with search and a filter. Pick some and import them.
* A clip arrives as an MP4 video, with its thumbnail as the poster image. A quote card arrives as a picture in 4:5, 1:1 or 9:16, with the quote as alternative text.
* Admins and owners in ShowBits can also **send a released clip to the site**; it lands in the media library by itself.
* Nothing leaves ShowBits before the episode's embargo ends, and only released clips at all: ShowBits checks this for every file.
* Imported files are ordinary WordPress attachments. They stay when a clip changes in ShowBits; importing a new version adds a new attachment, so posts that show the old one do not break.
* Everyone who may upload media can use the library; people who manage the site connect it.
* Translated into 68 languages and variants of WordPress, including right-to-left languages.

= Connecting =

In ShowBits, an admin or owner of the workspace makes a site key for this site's address (Integrations → WordPress) and passes it on. Here, under Settings → ShowBits, paste it and connect. The key is valid for 24 hours and only once. If you are an admin or owner in ShowBits yourself, you can instead connect through ShowBits: sign in there and agree (OAuth 2.1 style, with PKCE).

While connecting, ShowBits checks at this site's REST API that it is talking to the plugin that asked, so a site key copied anywhere else connects nothing. The site then gets a key of its own that only opens this plugin's routes in ShowBits (not ShowBits' REST API), and a secret that ShowBits signs its pushes with. Both are stored encrypted with a key from wp-config.php (`SHOWBITS_ENCRYPTION_KEY`, or the site's salts). The connection lasts until someone disconnects it in ShowBits or here.

= Requirements =

* A ShowBits workspace, and an admin or owner of it who makes the site key.
* The site is served over HTTPS and ShowBits can reach its REST API (`/wp-json/showbits/v1/push`): every file comes to the site as a push from ShowBits, also the ones picked in the library. A firewall or security plugin must let these requests through.
* WP-Cron runs for what ShowBits sends on its own (imports picked in the library finish while the page is open); a real cron job calling wp-cron.php is best.

= For developers =

* `showbits_capability` – who may browse and import (default `upload_files`).
* `showbits_menu_label` – the name in the menus (default "ShowBits").
* `showbits_allow_private_media` – allow downloading from a media store on a private network (OnPremise).
* `showbits_max_download_bytes` – the largest file to download (default 2 GB).
* `SHOWBITS_ENCRYPTION_KEY` in wp-config.php – the key for the stored secrets, instead of one derived from the salts.

== External services ==

This plugin connects to **ShowBits**, a video clipping service by HBC Hohn Business Consulting UG (haftungsbeschränkt), Berlin, Germany. It needs ShowBits to list and deliver your workspace's clips and quote cards; without a connection it does nothing. By default it talks to ShowBits Cloud at `https://app.showbits.ai`. With ShowBits OnPremise, an administrator enters the address of that installation before connecting, and all of the following goes there instead.

What is sent, and when:

* **When an administrator connects the site** (Settings → ShowBits): the site key, the site's address and title, the address of the plugin's REST route, the plugin's version and a random one-time value. With "Connect through ShowBits", the administrator's browser goes to ShowBits' consent page with the site's address and title, the address to come back to and a PKCE challenge. ShowBits then sends one check to the site's REST route.
* **On the settings page**: the connection's status (workspace name, imports today), asked at most every five minutes.
* **On Media → ShowBits**: the search term, filter and page of the library. Your browser loads the preview pictures from ShowBits through short-lived links.
* **When a clip is imported** (picked in the library, or sent from ShowBits): the clip's id and format; then the plugin downloads the files from the short-lived link ShowBits returns, and tells ShowBits whether the import worked, with the attachment's address in the media library or the reason it failed.
* **When the site is disconnected, or the plugin is deleted**: a request that ends the connection in ShowBits.

Once connected, requests to ShowBits carry the site's own key; file downloads go without it. Nothing is sent about the site's visitors, and the plugin adds nothing to the public site.

* ShowBits terms of service: https://showbits.ai/en/terms
* ShowBits privacy policy (section "WordPress (ShowBits plugin)"): https://showbits.ai/en/privacy#wordpress

== Installation ==

1. Install the plugin through Plugins → Add New Plugin (search for "ShowBits"), or upload the ZIP under Plugins → Add New Plugin → Upload Plugin, and activate it.
2. In ShowBits, an admin or owner of the workspace opens Integrations → WordPress and makes a site key for this site's address.
3. In WordPress, go to Settings → ShowBits. With ShowBits OnPremise, enter your installation's address first. Paste the site key and click **Connect**. (Or click **Connect through ShowBits** if you are an admin or owner in ShowBits yourself.)
4. Go to Media → ShowBits, pick clips and quote cards, and click **Import selected**.

== Frequently Asked Questions ==

= Do I need a ShowBits account? =

Yes. The plugin brings clips and quote cards from a ShowBits workspace into WordPress; without one it has nothing to show. It works with every ShowBits plan (Creator, Business, Enterprise) and with ShowBits OnPremise.

= Is the plugin free? =

The plugin is free and open source (GPLv2 or later), with no locked features. ShowBits itself is a paid service: your ShowBits plan sets how many websites a workspace can connect and how many imports a website gets a day, and ShowBits applies these limits on its side.

= Who can see the imported files? =

Imported files are ordinary media library uploads: like any upload, they can be opened by anyone who has their address. ShowBits only hands out clips that were released and whose embargo has ended.

= What happens to imported files when a clip changes in ShowBits? =

They stay as they are. A new cut or a redrawn card is a new version: importing it again adds a new attachment, so posts that show the old one do not break.

= What does ShowBits learn about my site? =

Its address and title, the address pushes go to, the plugin's version, when the plugin last asked, and for each push whether it was imported (with the attachment's address) or why not. See "External services" above.

= What does the plugin store in WordPress? =

Its settings, the connection (the site's key and the push secret encrypted), and a list of recent imports with the WordPress user who started each. Imported attachments keep the clip's id, version and format as post meta, so the library can show what is already there.

= What happens when I delete the plugin? =

It ends the connection in ShowBits (if ShowBits can be reached) and removes its settings, its table and its scheduled tasks. Files it imported stay in the media library.

= In which languages is the plugin? =

English and 68 other languages and variants of WordPress, from Albanian to Yorùbá, including formal and informal German and Dutch, and European Portuguese in both spellings. The translations come with the plugin: WordPress shows it in the language of your profile, and imported files get their titles and alternative texts in the site's language. They were machine-translated and are not yet reviewed by native speakers; corrections are welcome on translate.wordpress.org.

== Changelog ==

= 1.0.1 =
* A download cut short (the site's size limit, a connection that broke off) fails instead of importing a broken file.
* What an import tells ShowBits is sent again until ShowBits has it.
* One import of a clip at a time: a push and an import picked in WordPress at the same moment no longer make two attachments.
* Each connect attempt has its own pairing value: two at once no longer spoil each other.
* The settings page warns when the site's security keys are not in wp-config.php (the connection's key would be stored in the database).

= 1.0.0 =
* Connect to a ShowBits workspace with a site key (or through ShowBits with PKCE).
* Library of released clips and quote cards under Media → ShowBits.
* Imports with poster image and alternative text, delivered as signed pushes with a ticket; clips sent from ShowBits land in the media library by themselves.
* Translations into 68 languages and variants.

== Upgrade Notice ==

= 1.0.0 =
First release.
