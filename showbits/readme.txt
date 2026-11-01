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
* Nothing leaves ShowBits before the episode's embargo ends, and only released clips at all: ShowBits checks it for every file.

Everyone who may upload media can use the library; people who manage the site connect it.

= Connecting =

Settings → ShowBits → Connect with ShowBits. You are taken to ShowBits, where an admin or owner of the workspace agrees. ShowBits sends you back with a one-time code that only this site can exchange (OAuth 2.1 authorization code with PKCE). No token is copied anywhere.

The site gets a token that can only read the workspace's released clips, and a secret ShowBits signs its pushes with. Both are stored encrypted with a key from wp-config.php (`SHOWBITS_ENCRYPTION_KEY`, or the site's salts). The connection belongs to the person who agreed in ShowBits; it ends after a year, when they leave the workspace, or when someone disconnects it in ShowBits or here.

ShowBits is available in the Enterprise plan and in ShowBits OnPremise (enter your installation's address before connecting).

= Requirements =

* The site is served over HTTPS (ShowBits sends pushes only to https addresses).
* WP-Cron runs (pushes are imported in the background); a real cron job calling wp-cron.php is best.
* To receive pushes, ShowBits must reach the site's REST API (`/wp-json/showbits/v1/push`). Behind a firewall, importing from the library still works.

= Filters =

* `showbits_capability` – who may browse and import (default `upload_files`).
* `showbits_menu_label` – the name in the menus (default "ShowBits").
* `showbits_allow_private_media` – allow downloading from a media store on a private network (OnPremise).
* `showbits_max_download_bytes` – the largest file to download (default 2 GB).

== Frequently Asked Questions ==

= What happens to imported files when a clip changes in ShowBits? =

They stay as they are. A new cut or a redrawn card is a new version: importing it again adds a new attachment, so posts that show the old one do not break.

= What does ShowBits learn about my site? =

Its address and name, the address pushes go to, the plugin's version, and for each push whether it was imported (with the attachment's address) or why not.

== Changelog ==

= 1.0.0 =
* Connect to a ShowBits workspace (authorization code with PKCE), library of released clips and quote cards, imports with poster and alternative text, pushes from ShowBits.
