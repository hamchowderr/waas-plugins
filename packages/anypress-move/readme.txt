=== AnyPress Move ===
Contributors: otakusolutions
Tags: migration, fluentcrm, fluent forms, fluentcommunity, fluentcart
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.2
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Moves your forms, shop, contacts, help desk, affiliates, community, courses and members into the Fluent plugins, with a check first and an undo after.

== Description ==

Each switch copies one plugin's data into the Fluent plugin that replaces it, using the Fluent plugin's own importer where it has one:

* Forms: Contact Form 7, WPForms and Ninja Forms into Fluent Forms, with entries, and the pages that show them.
* Shop: WooCommerce into FluentCart, with the FluentCart Migrator add-on.
* Contacts: WooCommerce buyers, MailPoet and Newsletter subscribers into FluentCRM.
* Help desk: Awesome Support, SupportCandy or JS Help Desk into Fluent Support.
* Affiliates: AffiliateWP, Affiliates Manager, Solid Affiliate, SliceWP or Ultimate Affiliate Pro into FluentAffiliate.
* Community: BuddyPress or BuddyBoss into FluentCommunity.
* Courses: LearnDash, Tutor LMS or LifterLMS into FluentCommunity courses, with students and their progress.
* Circle: a Circle community into FluentCommunity, through Circle's Admin API.
* MemberPress: active members into FluentCommunity spaces and courses, with FluentCRM tags. Payments stay in MemberPress.

Open Tools > Move to Fluent. Check says what would come across, what can't, and anything in the way, and changes nothing. Switch runs it. Undo takes out only what the switch added. The old plugin and its data are never changed, and emails are held while a switch runs, so nobody gets a welcome email for a copied account. Take a backup before you switch.

The same switches run from WP-CLI (`wp anypress course switch --dry-run`, `wp anypress forms switch`, and so on) and as WordPress abilities, so AI tools connected through the MCP Adapter can check and run them.

Install the Fluent plugin a switch copies into first.

On an AnyPress website the switches are already built in, and this plugin does nothing.

= Source code =

All the code is plain, readable PHP. The source, with its tests, is at https://github.com/hamchowderr/waas-plugins (the switches live in packages/anypress-client and are copied into this plugin by scripts/build-move.sh).

== External services ==

This plugin connects to one outside service, and only when you run its Circle switch.

* **Circle** (circle.so), through Circle's Admin API at `https://app.circle.so/api/admin/v2/`. It is used to read your Circle community (spaces, members, posts, comments and courses) so the switch can copy them into FluentCommunity. Requests are sent only when an administrator runs Check or Switch for Circle, and they carry the Admin API token the administrator types in, which is used for that run only and never saved. Nothing is sent to Circle beyond those read requests. Circle's terms: https://circle.so/terms , privacy policy: https://circle.so/privacy

No other switch sends anything off your site. The Shop switch links to FluentCart's own page for its free Migrator add-on but never downloads it; you install it yourself.

== Changelog ==

= 1.0.2 =
* The switches read and write through each plugin's own functions (FluentCRM, Fluent Forms, FluentCommunity, Fluent Support, FluentAffiliate, FluentCart, BuddyPress, MailPoet, WooCommerce) and WordPress's own post, user and comment queries, instead of their database tables. The only direct reads left are of tables a plugin keeps for itself and offers no function for while it is switched off (MemberPress, LearnDash and LifterLMS progress, MailPoet's sign-up IP addresses, The Newsletter Plugin's subscribers).

= 1.0.1 =
* Files can't be opened directly from the web.
* The readme lists the outside service the Circle switch uses.


= 1.0.0 =
* First release: the forms, shop, contacts, help desk, affiliates, community, courses, Circle and MemberPress switches, with the Move to Fluent page under Tools.
