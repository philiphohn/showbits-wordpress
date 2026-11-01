=== ShowBits ===
Contributors: showbits
Tags: video, media library, clips, podcast, editorial
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Released clips and quote cards from ShowBits in your media library.

== Description ==

ShowBits turns podcast and TV episodes into clips, subtitles and quote cards; your editors approve them there. This plugin brings what they released into WordPress:

* **Media → ShowBits** lists the released clips and quote cards of the connected ShowBits workspace, newest first. Pick some and import them: a clip's video (with its thumbnail as the poster image), a quote card's picture in 4:5, 1:1 or 9:16 (with the quote as alternative text).
* Admins and owners in ShowBits can **send a released clip to this site**; it lands in the media library by itself.
* Files reach only this site: ShowBits sends each one as a signed push with a ticket that is good for that clip alone.
* Nothing leaves ShowBits before the episode's embargo ends, and only released clips at all: ShowBits checks it for every file.

Everyone who may upload media can use the library; people who manage the site connect it.

= Connecting =

In ShowBits, an admin or owner of the workspace makes a site key for this site's address (Integrations → WordPress) and passes it on. Here, under Settings → ShowBits, paste it and connect. The key is valid for 24 hours and only once. If you are an admin or owner in ShowBits yourself, you can instead connect through ShowBits: sign in there and agree (OAuth 2.1 style, with PKCE).

While connecting, ShowBits checks at this site's REST API that it is talking to the plugin that asked, so a site key copied anywhere else connects nothing. The site then gets a key of its own that only opens this plugin's routes (not ShowBits' REST API), and a secret ShowBits signs its pushes with. Both are stored encrypted with a key from wp-config.php (`SHOWBITS_ENCRYPTION_KEY`, or the site's salts). The connection belongs to the workspace; it lasts until someone disconnects it in ShowBits or here.

The plugin works in every ShowBits plan (Creator, Business, Enterprise) and with ShowBits OnPremise (enter your installation's address before connecting). Each plan allows a number of sites per workspace and of imports a day.

= Requirements =

* The site is served over HTTPS and ShowBits can reach its REST API (`/wp-json/showbits/v1/push`): every file comes to the site as a push from ShowBits, also the ones picked in the library.
* WP-Cron runs for what ShowBits sends on its own (imports picked in the library finish while the page is open); a real cron job calling wp-cron.php is best.

= Filters =

* `showbits_capability` – who may browse and import (default `upload_files`).
* `showbits_menu_label` – the name in the menus (default "ShowBits").
* `showbits_allow_private_media` – allow downloading from a media store on a private network (OnPremise).
* `showbits_max_download_bytes` – the largest file to download (default 2 GB).

== Frequently Asked Questions ==

= What happens to imported files when a clip changes in ShowBits? =

They stay as they are. A new cut or a redrawn card is a new version: importing it again adds a new attachment, so posts that show the old one do not break.

= What does ShowBits learn about my site? =

Its address and name, the address pushes go to, the plugin's version, when the plugin last asked, and for each push whether it was imported (with the attachment's address) or why not.

== Changelog ==

= 1.0.0 =
* Connect to a ShowBits workspace with a site key (or through ShowBits with PKCE), library of released clips and quote cards, imports with poster and alternative text as signed pushes with a ticket, pushes from ShowBits.
