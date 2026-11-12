=== AnyPress Move ===
Contributors: otakusolutions
Tags: migration, fluentcrm, fluent forms, fluentcommunity, fluentbooking
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.4.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Moves your forms, shop, contacts, bookings, help desk, affiliates, community, courses and members into the Fluent plugins, with an undo.

== Description ==

Each switch copies one plugin's data into the Fluent plugin that replaces it, using the Fluent plugin's own importer where it has one:

* Forms: Contact Form 7, WPForms, Ninja Forms and Gravity Forms into Fluent Forms, with entries, and the pages that show them.
* Shop: WooCommerce into FluentCart, with the FluentCart Migrator add-on.
* Digital shop: Easy Digital Downloads 3 into FluentCart, with the FluentCart Migrator add-on, and the pages that show it.
* Contacts: WooCommerce buyers, MailPoet and Newsletter subscribers into FluentCRM.
* Help desk: Awesome Support, SupportCandy or JS Help Desk into Fluent Support.
* Affiliates: AffiliateWP, Affiliates Manager, Solid Affiliate, SliceWP or Ultimate Affiliate Pro into FluentAffiliate.
* Community: BuddyPress or BuddyBoss into FluentCommunity.
* Courses: LearnDash, Tutor LMS or LifterLMS into FluentCommunity courses, with students and their progress.
* Circle: a Circle community into FluentCommunity, through Circle's Admin API.
* Skool: a Skool group's members into FluentCommunity, from the members CSV you export from the group's Members tab, with their answers to the membership questions in FluentCRM. Nothing signs in to Skool. Skool has no export for the classroom or posts, so those are rebuilt in FluentCommunity.
* Calendly: a Calendly account into FluentBooking, through Calendly's API: availability schedules, event types and upcoming bookings.
* MemberPress: active members into FluentCommunity spaces and courses, with FluentCRM tags. Tick billing and active Stripe subscriptions move to FluentCart, where they keep renewing in Stripe; PayPal and other gateways stay in MemberPress.

Open Tools > Move to Fluent. Check says what would come across, what can't, and anything in the way, and changes nothing. Switch runs it. Undo takes out only what the switch added. The old plugin and its data are never changed, and emails are held while a switch runs, so nobody gets a welcome email for a copied account. Take a backup before you switch.

The same switches run from WP-CLI (`wp anypress course switch --dry-run`, `wp anypress forms switch`, and so on) and as WordPress abilities, so AI tools connected through the MCP Adapter can check and run them.

Install and turn on the Fluent plugin a switch copies into first (and, for the shop, FluentCart's free Migrator add-on). The switches never turn plugins on or off; Check says which one is still off.

On an AnyPress website the switches are already built in, and this plugin does nothing.

= Source code =

All the code is plain, readable PHP. The source, with its tests, is at https://github.com/hamchowderr/waas-plugins (the switches live in packages/anypress-client and are copied into this plugin by scripts/build-move.sh).

== External services ==

This plugin connects to two outside services, each only when you run its switch: Circle for the Circle switch, and Calendly for the Calendly switch.

* **Circle** (circle.so), through Circle's Admin API at `https://app.circle.so/api/admin/v2/`. It is used to read your Circle community (spaces, members, posts, comments and courses) so the switch can copy them into FluentCommunity. Requests are sent only when an administrator runs Check or Switch for Circle, and they carry the Admin API token the administrator types in, which is used for that run only and never saved. Nothing is sent to Circle beyond those read requests. Circle's terms: https://circle.so/terms , privacy policy: https://circle.so/privacy

* **Calendly** (calendly.com), through Calendly's API at `https://api.calendly.com/`. It is used to read your Calendly account (your user, availability schedules, event types, upcoming scheduled events and their invitees) so the switch can copy them into FluentBooking. Requests are sent only when an administrator runs Check or Switch for Calendly, and they carry the personal access token the administrator types in, which is used for that run only and never saved. Nothing is sent to Calendly beyond those read requests. Calendly's terms: https://calendly.com/legal/customer-terms-conditions , privacy notice: https://calendly.com/legal/privacy-notice

No other switch sends anything off your site. The Skool switch reads only the CSV file you upload, which is used for that run and never saved. The Shop switch links to FluentCart's own page for its free Migrator add-on but never downloads it; you install it yourself.

== Changelog ==

= 1.4.0 =
* Skool: upload the members CSV from a Skool group's Members tab (Export) and its members become WordPress users (no email is sent) in one FluentCommunity space or course, or a new private "Skool members" space. With FluentCRM on they are tagged "Skool member" and each answer to a membership question goes into a FluentCRM custom field. Rows without an email are listed and skipped, and Undo takes it all back out.

= 1.3.0 =
* Calendly: a Calendly account moves to FluentBooking through Calendly's API with a personal access token: availability schedules with their weekly and dated hours, event types as booking types (length, description, locations, seats for group events, inactive ones as drafts), and upcoming bookings with each invitee's name, email, time zone, answers and meeting link. No booking email is sent for what comes across, and Undo takes it all back out.

= 1.2.0 =
* Digital shop: an Easy Digital Downloads 3 shop moves to FluentCart through the FluentCart Migrator add-on's own EDD migration (downloads, orders, customers, coupons, subscriptions and licences), and EDD's shortcodes and blocks on the site's pages, its own checkout and account pages included, become FluentCart's. Undo puts the pages back.
* Forms: Gravity Forms forms and their entries move to Fluent Forms, through Fluent Forms' own importer with its mistakes put right (hidden name and address parts stay hidden, dates and times keep their format, entry limits and messages keep their values, the default confirmation stays the main one, and entries keep the visitor's IP address, browser and page). Pages, blocks and drafts that show a Gravity Forms form are swapped, and Check lists the show/hide rules and add-on feeds that don't come across.

= 1.1.1 =
* The plugin's website link goes to anypress.io/move.

= 1.1.0 =
* MemberPress: with billing ticked, active Stripe subscriptions move to FluentCart. Each keeps the same Stripe subscription, so Stripe goes on charging as before and FluentCart records the renewals; connect FluentCart's Stripe settings to the same Stripe account, then switch off MemberPress's Stripe gateway. Undo keeps any subscription FluentCart has already recorded a payment on.

= 1.0.3 =
* The switches never turn other plugins on or off. When the Fluent plugin a switch copies into (or FluentCart's Migrator add-on) is off, Check says so and asks you to turn it on.
* The Move to Fluent page loads its few lines of style and its "are you sure?" prompts the WordPress way, on that page only.

= 1.0.2 =
* The switches read and write through each plugin's own functions (FluentCRM, Fluent Forms, FluentCommunity, Fluent Support, FluentAffiliate, FluentCart, BuddyPress, MailPoet, WooCommerce) and WordPress's own post, user and comment queries, instead of their database tables. The only direct reads left are of tables a plugin keeps for itself and offers no function for while it is switched off (MemberPress, LearnDash and LifterLMS progress, MailPoet's sign-up IP addresses, The Newsletter Plugin's subscribers).

= 1.0.1 =
* Files can't be opened directly from the web.
* The readme lists the outside service the Circle switch uses.


= 1.0.0 =
* First release: the forms, shop, contacts, help desk, affiliates, community, courses, Circle and MemberPress switches, with the Move to Fluent page under Tools.
