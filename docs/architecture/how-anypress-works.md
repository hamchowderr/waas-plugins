# How AnyPress Works, in Plain Words

This page explains the whole system without the code words. It covers what each part is for, who uses it, and what happens when someone buys a website, logs in, stops paying or asks the assistant for a change. The other pages in this folder have the developer detail: file names, routes and settings.

[Back to the overview](README.md)

## A Few Words First

| Word | What it means here |
|---|---|
| **Shop** | The WordPress site where websites are sold, such as shop.anypress.io. A seller or reseller can have their own shop. In the code this is called a **storefront**. |
| **Website** | A customer's own WordPress site, made and run by AnyPress. In the code this is called a **store** or a **tenant**. |
| **Owner** | The customer who bought a website and runs it. |
| **AnyPress API** | The control centre. It builds every website, gives it an address, backs it up, pauses it and deletes it. Nothing reaches a website's server except through it. |
| **Server** | The machine the websites run on. Coolify starts and stops each website there, and each one gets its own WordPress and its own database. |
| **Template** | The master copy every new website is made from. It already has the AnyPress theme, AnyPress Blocks, AnyPress Client and the Fluent plugins in it. |
| **Plan** | What the owner paid for, such as Website, Bookings or Store. The plan decides which plugins are switched on. |
| **Seller** | Someone who sells websites under their own name, from their own shop. |
| **Reseller** | A seller with several shops on one monthly bill. Their websites carry the reseller's brand. |
| **Dashboard** | app.anypress.io, where sellers, resellers and AnyPress see and manage everything. |

## The Whole System

```mermaid
flowchart TB
  OWNER(["Website owner"])
  SELLER(["Seller or reseller"])
  VISIT(["Visitors and callers"])

  subgraph SHOP["The shop"]
    CART["FluentCart<br/>checkout and monthly payments<br/>(AnyPress Payments adds cards)"]
    HOST["AnyPress Host<br/>turns each sale into a website"]
    CART --> HOST
  end

  subgraph CENTRE["Run by AnyPress"]
    API["AnyPress API<br/>the control centre"]
    DASH["Dashboard<br/>app.anypress.io"]
    APPS["Connections<br/>Claude, ChatGPT, n8n,<br/>Zapier, Make, HighLevel"]
    HELP["Helpers<br/>website assistant,<br/>phone receptionist"]
    DASH --> API
    APPS --> API
    HELP --> API
  end

  SERVER["Server with Coolify"]

  subgraph SITE["Each website, its own WordPress"]
    direction LR
    CLIENT["AnyPress Client"]
    LOOK["AnyPress theme<br/>and AnyPress Blocks"]
    FLUENT["Fluent plugins<br/>for the plan"]
  end

  OWNER -->|"buys"| CART
  SELLER -->|"manages"| DASH
  HOST -->|"build, pause or delete"| API
  API -->|"builds and runs"| SERVER
  SERVER --> SITE
  OWNER -->|"logs in, asks the assistant"| SITE
  VISIT -->|"visit and phone"| SITE
```

Here is what each part does:

- **The shop** sells websites. FluentCart handles the checkout and the monthly payments, with Stripe or, through AnyPress Payments, Authorize.Net. **AnyPress Host** watches those payments and tells the AnyPress API what to do: build a website, pause it, bring it back or delete it.
- **The AnyPress API** is the only thing that builds or changes websites. It works through Coolify on the server, and each website gets its own WordPress and database, so one website can't see another.
- **The dashboard** at app.anypress.io is where sellers, resellers and AnyPress itself see every website, shop, payment and job. It shows each seller's own brand.
- **Connections** let other tools use the AnyPress API: Claude and ChatGPT through the AnyPress connector, the n8n, Zapier and Make apps, the HighLevel app, and webhooks.
- **AnyPress Client** is already on every website, because it's in the template. It handles the one-click login, switches plugins on and off for the plan, and gives the assistant, the phone receptionist and AI apps a safe way to make changes.
- **The AnyPress theme and AnyPress Blocks** give every new website its look. Owners restyle it in the Site Editor, and the assistant uses the same colours, fonts and sections.
- **The website assistant** sits beside the page editor on each website, at the website's own address (`/.assistant`). It changes pages only after the owner says yes.
- **The phone receptionist** answers a business's phone in the business's name, offers open times, books visits in FluentBooking and saves each caller in FluentCRM.

Three rules hold the system together:

1. **The API is in charge of websites.** The shop, the dashboard, the assistant, the phone receptionist and the automation apps all ask the API to build or change a website, and the API tells the shop when something is done. The one exception is an AI app the owner connects to their website's own MCP server: it signs in to the website as a WordPress user.
2. **Every website stands alone.** Each one has its own WordPress and its own database on the server, and the API signs every request to it with that website's own key. One website can't see another, and one going wrong doesn't touch the rest.
3. **A website keeps working when the shop or the API is down.** Its pages, forms, bookings and orders keep working for visitors. Only new websites and changes made through the API wait until it is back.

## Buying a Website

```mermaid
sequenceDiagram
  autonumber
  actor O as Owner
  participant S as Shop
  participant A as AnyPress API
  participant W as New website

  O->>S: Picks a plan and a website name
  S->>A: Is this name free?
  A-->>S: Yes
  O->>S: Pays
  S->>A: Build a website for this owner
  A->>W: Copies the template and starts it on the server
  A-->>S: The website is ready
  S->>W: Checks the secure address (https) works
  S->>O: Email: "Your website is ready"
  O->>S: Clicks "Log in" on My website
  S-->>O: A one-time login link
  O->>W: Opens the link and lands in WordPress
```

- Building takes a few minutes. The owner can watch it on **My website**, where it goes through *Queued*, *Setting up*, *Linking the address*, *Getting the secure certificate* and then *Ready*.
- If someone takes the name between checkout and building, the shop asks the owner for a new one and emails them and the admin.
- If a step fails, the shop tries again after 1, 5, 15, 30 and 60 minutes. After the sixth failure it emails the admin, who can press **Retry**.
- The owner's business details from checkout (name, phone, address, what the business does) go into the website, so the assistant and the receptionist know about the business from day one.

## Logging In With One Click

The shop and the website never share a password. When the website is made, the shop makes a matching pair of keys for it. The shop keeps one key and gives the other to the website.

```mermaid
sequenceDiagram
  autonumber
  actor O as Owner
  participant S as Shop
  participant W as Website

  Note over S,W: Once, when the website is made
  S->>S: Makes a key pair, keeps one half
  S->>W: The other half goes into the website's settings

  Note over O,W: Each time the owner clicks "Log in"
  O->>S: Log in to my website
  S->>S: Checks this person owns the website
  S-->>O: A link locked with the shop's key, good for 5 minutes
  O->>W: Opens the link
  W->>W: Opens the lock with its own key, checks it's for this website, still fresh and not used before
  W-->>O: Signed in to WordPress
```

- Each link works once and only for a few minutes, so a link in someone's browser history is useless later.
- When anything is wrong, the website gives the same "no" without saying why, so nobody learns how to fake a link.
- The dashboard has its own **Log in to WordPress** button, which works the same way with a link the AnyPress API signs.

## The Plan Decides Which Plugins Are On

Each plan has a list of plugins. For example, Website turns on Fluent Forms and FluentCRM, and Store adds FluentCart. The list is saved in the template, so every website has a copy.

```mermaid
flowchart LR
  T["The template<br/>the plan lists are edited here"] -->|"copied into"| W["Every new website"]
  S["The shop<br/>knows each product's plan"] -->|"tells the website its plan<br/>when it is built"| W
  W --> C{"On every page load,<br/>AnyPress Client compares"}
  C -->|"in the plan"| ON["Switched on"]
  C -->|"not in the plan"| OFF["Switched off"]
```

- If the plan can't be found, AnyPress Client changes nothing and shows the owner a notice, so a mistake can never switch everything off.
- The owner can see their plan, and the plugins it includes, on **Dashboard > Your plan**.
- The shop can stop owners installing or removing plugins themselves, so the plan stays as sold.

## When Payments Stop, and Start Again

```mermaid
stateDiagram-v2
  [*] --> Building: payment taken
  Building --> Ready: website is live
  Ready --> Paused: payment on hold
  Paused --> Ready: payment comes in
  Ready --> Ended: subscription ends or is cancelled
  Ended --> Ready: owner pays again
  Ended --> Deleted: 30 days pass
  Deleted --> Building: owner pays again,<br/>restored from the last backup
```

- **Paused**: visitors see a "this website isn't available" page, but nothing is lost.
- **Ended**: the shop takes a backup first, then pauses the website. It is deleted 30 days later unless the owner pays again.
- **Cancelled with time left**: the website stays up until the paid time runs out.
- **Paying again after deletion** brings the website back from its last backup, or builds a fresh one if no backup is left.
- With AnyPress Payments (Authorize.Net), a renewal is never charged twice. If the bank doesn't answer, it looks the charge up the next day instead of charging again. FluentCart retries a declined card over the 7-day grace period and emails the owner a **Pay now** link.

## How the Shop Hears Back From the API

When a website is ready, restored, deleted or gets a new address, the API tells the shop straight away with a signed message, called a webhook. The shop also checks in every minute, in case a message goes missing.

```mermaid
sequenceDiagram
  participant A as AnyPress API
  participant S as Shop

  S->>A: Please tell me when my websites change
  A-->>S: OK, here's a secret to sign the messages with
  A->>S: "Website 123 is ready", signed
  S->>S: Is the signature right, is the message recent, and is it new?
  S->>A: What does website 123 look like now?
  S->>S: Shows Ready and sends the email
  loop Every minute, as a backup
    S->>A: Anything still on its way?
  end
```

- A message with a wrong signature, or one older than 5 minutes, is refused. A message that arrives twice is acted on once.
- The message only says *which* website changed. The shop always asks the API for the latest, so it never acts on old news.

## Updates

AnyPress Host and AnyPress Payments update themselves on the shop. Each update is signed when it is released, and the shop checks the signature before installing it. AnyPress Client, the theme and AnyPress Blocks don't update on each website. A new template version carries them, and the API moves every website onto it.

```mermaid
flowchart LR
  REL["New release<br/>built and signed"] --> STORE[("Release storage")]
  STORE --> API["AnyPress API"]
  API -->|"a new version is out"| SHOP["The shop"]
  SHOP --> CHECK{"Is the file intact<br/>and signed by AnyPress?"}
  CHECK -->|"yes"| INSTALL["Installs it"]
  CHECK -->|"no"| BIN["Deletes the download,<br/>keeps the old version"]
  TPL["New template version<br/>new Client, theme, Blocks, WordPress"] -->|"made Production"| API
  API -->|"moves every website"| SITES["All websites"]
```

- A download that fails either check is thrown away before WordPress touches the installed plugin.
- When a new template version becomes Production, the API moves every website onto it. If a website fails to move, the API puts it back on the old version.

## Changing a Website With AI

There are three ways in, and they all use the same safe tools on the website. They can change pages, sections, colours, fonts, the header, the footer, menus and images, and nothing else: no plugins, no settings, no orders.

```mermaid
flowchart LR
  OWNER(["Owner"]) -->|"chat beside the editor"| ASSIST["Website assistant<br/>at the website's own /.assistant"]
  AI(["Claude or ChatGPT"]) -->|"AnyPress connector"| API["AnyPress API"]
  ASSIST --> API
  API -->|"request signed with<br/>this website's key"| CLIENT["AnyPress Client<br/>site tools"]
  WPAI(["AI tools inside WordPress"]) -->|"WordPress abilities,<br/>as the signed-in user"| CLIENT
  CLIENT --> PAGES["Pages, styles, menus<br/>every change saved as a revision"]
```

- Before an app can work on a website, the owner signs in with **Sign in with your website** and presses **Allow** on their own website.
- The assistant reads the business profile first, so it knows what the business is. It waits for the owner's yes before any change visitors would see. New pages always start as drafts.
- WordPress keeps a revision of every change, so any change can be undone.
- Each plan comes with a monthly number of assistant messages: 150, 300 or 600 for Small, Medium and Large.

## The Phone Receptionist

```mermaid
sequenceDiagram
  actor C as Caller
  participant P as Phone receptionist
  participant A as AnyPress API
  participant W as The business's website

  C->>P: Rings the business's number
  P->>A: Which website has this number?
  A-->>P: This one, and its business details
  P->>C: Answers in the business's name
  C->>P: "Can I book for Tuesday?"
  P->>A: Open times?
  A->>W: Asks FluentBooking
  W-->>P: Open times
  P->>C: Offers times, books one
  P->>A: Save the booking and the caller
  A->>W: Booking in FluentBooking, caller in FluentCRM
```

- Urgent callers can be put through to the owner's mobile, or the receptionist takes a message.
- Every call is logged and counted against the website's monthly minutes. When the minutes run out, it still answers and takes messages but doesn't book.

## Moving an Existing Website In

```mermaid
flowchart LR
  OLD["The old website<br/>on any host"] -->|"AnyPress Export plugin<br/>and an import code"| API["AnyPress API"]
  API --> NEW["A new AnyPress website<br/>with the same content and theme"]
  NEW --> SW["Optional switches"]
  SW --> F["Forms to Fluent Forms"]
  SW --> SH["WooCommerce shop to FluentCart"]
  SW --> CRM["Buyers and subscribers to FluentCRM"]
  SW --> OTHER["Help desk, affiliates, community<br/>to the Fluent plugins"]
```

- The owner gets an import code on AnyPress, installs **AnyPress Export** on the old website and sends it. A big upload goes in parts, so it can carry on after a break.
- The moved website keeps its own theme and content. The Fluent plugins are installed but switched off.
- Each switch has a dry run that changes nothing and reports what it would do, and an undo. The old plugins and their data are never changed or deleted.
- The CRM switch never sends emails while it runs, and only marks people as subscribed where they agreed to it.

The rest of this page lists the features around those main parts, grouped by who they are for.

## Inside Every Website

What an owner gets without asking.

- **Its own address.** Every website starts at name.anypress.site. The owner can add their own domain. Where their DNS host supports it, one Connect automatically button adds the records for them. Otherwise the page lists the records to copy.
- **Email that arrives.** Websites send mail through toSend, from their own mailbox at anypress.io, or from the owner's own domain once its records check out. Every email has the same clean AnyPress look. Sellers' websites carry their brand.
- **Backups every night.** Every live website is backed up each night: its files and its database. Each night one backup is also restored into a throwaway copy and checked, so the backups are proven to work.
- **A CRM that's set up.** FluentCRM comes ready with tags for what people are interested in, have bought or used to buy, a contact form that feeds it, and draft follow-up emails the owner can switch on.
- **The website assistant.** A chat beside the page editor that changes pages, colours, menus and images on request. It checks with the owner before anything visitors would see. Plans include 150, 300 or 600 messages a month by size.
- **Room for AI apps.** On Your plan, the owner can switch on the website's own MCP server, so AI apps they trust can work on its pages. It only reaches pages and design, never settings or orders.
- **My website.** On the shop's account page the owner logs in with one click, adds their own domain and sets up their email sender. If the website's name was taken, it asks for a new one.
- **A phone receptionist.** For local service businesses, it answers calls in the business's name, offers open times, books visits and saves each caller. Calls count against monthly minutes.

## Selling Websites

Sellers sell under their own name. Resellers run several shops on one bill.

- **Sign in with a code.** People sign in to the dashboard with their email. The email has a link and a 6-digit code, so it still works when a work mail filter opens the link first. Anyone invited to a team sees a Join button.
- **Their own brand.** The dashboard, the emails and the plugin names show the seller's name, logo and colour, for example "KnowBS Client". Sellers can even give the dashboard their own address.
- **Pick the plugins.** A seller can add plugins from wordpress.org, upload premium ones, or switch some off. Their choices go into every new version of their websites.
- **Setup checklists.** Each shop sees what it has set up so far, ticked from what's really there. AnyPress sees everyone's progress and who is stuck.
- **A sales kit.** The Sell page finds local businesses to approach, sends outreach from the seller's own mailbox, and adds ready email sequences to FluentCRM or HighLevel as drafts.
- **One monthly bill.** Resellers pay wholesale, 40% off, on one Stripe invoice on the 1st of each month. With a card on file it's charged automatically.
- **Shops that keep up.** A seller's shop follows the template it started from, and every new AnyPress Host release is installed on every shop automatically.
- **Team.** Owners of a seller account invite their team by email and choose who is an admin and who is a member.

## Connecting Other Tools

All of these live on the dashboard's Developers > Integrations page.

- **Integrations page.** One page lists every way in, for sellers and resellers: AI apps, automation apps and building your own, each with its setup steps.
- **The AnyPress connector.** Claude and ChatGPT can create websites and edit their pages through the AnyPress connector (MCP), after the person signs in and allows it.
- **HighLevel agencies.** An agency installs the AnyPress app and signs in to AnyPress from its main account, with no keys to copy. An agency without a shop gets one as it signs in. Each website belongs to the sub-account that made it, and triggers only fire there.
- **n8n, Zapier and Make.** Ready-made apps for each. Workflows can create websites, change them, and start when something happens to one.
- **Your own code.** The AnyPress API with a key and secret, SDKs for TypeScript and PHP, and webhooks that tell your app when a website is ready, restored or deleted.

## Keeping It Running

The parts nobody sees until something goes wrong.

- **Template versions.** Each change to the master copy becomes a new version. Making one Production moves every website onto it. A website that fails to move goes back to the version it was on.
- **Fresh WordPress images.** The installer adds a nightly job that downloads the newest WordPress and database images, so new and restarted websites start on the latest security fixes.
- **Retries and alerts.** Every job in the background tries again on its own. When one fails for good, AnyPress gets an email straight away, so a paid website is never left unbuilt.
- **Watching the server.** The API watches the server's load and its own health, and outside monitors check them around the clock.
- **Run it yourself.** The whole platform is open source. A one-command installer sets up the API, the database, DNS, the templates, a shop and the dashboard on your own server.
- **Moving a site in.** The AnyPress Export plugin sends any WordPress site in with an import code. Switches then move its forms, shop, CRM, help desk, affiliates and community to the Fluent plugins, with a dry run and an undo.

## For Developers

The rest of this folder has the code-level detail behind each part of this page:

| Part of this page | Developer page |
|---|---|
| The whole system, plans and updates | [How the Plugins Work](README.md) |
| Buying, payments stopping, the shop hearing back | [AnyPress Host](anypress-host.md) |
| One-click login, plan plugins, AI editing, the phone receptionist, moving a site in | [AnyPress Client](anypress-client.md) |
| Card payments and renewals | [AnyPress Payments](anypress-payments.md) |
| AI editing routes and MCP | [Site Editing API](../site-editing-api.md) |
| My website on the shop | [Account API](../account-api.md) |
| Signed updates | [Releases and Updates](../releases.md) |
