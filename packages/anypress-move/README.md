# AnyPress Move

Moves a WordPress site's forms, shop, contacts, help desk, affiliates, community, courses and members into the Fluent plugins, on any WordPress site. The switches are the ones AnyPress Client runs on AnyPress websites; this plugin carries them on their own, with a page under **Tools → Move to Fluent**.

| Switch | From | Into |
|---|---|---|
| Forms | Contact Form 7, WPForms, Ninja Forms, Gravity Forms | Fluent Forms |
| Shop | WooCommerce | FluentCart (with the FluentCart Migrator add-on) |
| Digital shop | Easy Digital Downloads 3 | FluentCart (with the FluentCart Migrator add-on) |
| Contacts | WooCommerce buyers, MailPoet, Newsletter | FluentCRM |
| Help desk | Awesome Support, SupportCandy, JS Help Desk | Fluent Support |
| Affiliates | AffiliateWP, Affiliates Manager, Solid Affiliate, SliceWP, Ultimate Affiliate Pro | FluentAffiliate |
| Community | BuddyPress, BuddyBoss | FluentCommunity |
| Courses | LearnDash, Tutor LMS, LifterLMS | FluentCommunity courses |
| Circle | Circle (Admin API token) | FluentCommunity |
| Calendly | Calendly (personal access token) | FluentBooking |
| MemberPress | MemberPress memberships (and, with billing, Stripe subscriptions) | FluentCommunity spaces and courses, FluentCRM tags, FluentCart subscriptions |

Each one has **Check** (says what would come across and changes nothing), **Switch** and **Undo** (takes out only what the switch added). The source plugin is never changed, and email is held while a switch runs. The same switches run as `wp anypress <switch> switch` and as WordPress abilities (`anypress/check-<switch>`, `anypress/switch-<switch>`). With AnyPress Client active, AnyPress Move does nothing.

## Building

The switches live in `packages/anypress-client`. `scripts/build-move.sh` copies them into this plugin's `lib/` folder in its own namespace and text domain; the release build runs it, so `lib/` isn't kept in git. To try it from a checkout:

```
scripts/build-move.sh
cd packages/anypress-move && composer install
```

`npm run move` in `scripts/playground` checks it on WordPress Playground.

The wordpress.org listing's icon and banner images are in `.wordpress-org/` (they go in the plugin's SVN `assets` folder, not the zip).
