# WordPress agent skills

These are copies of every skill in [WordPress/agent-skills](https://github.com/WordPress/agent-skills) (GPL-2.0-or-later),
taken at commit `7374e6f1`. They teach coding agents WordPress's own conventions; Claude Code loads them from this folder.

| Skill | Use it for |
|---|---|
| `wordpress-router` | Working out what kind of WordPress code a task touches and which skill fits |
| `wp-project-triage` | Detecting a project's type, tooling and versions |
| `wp-plugin-development` | Plugin architecture, hooks, settings, security, release packaging |
| `wp-plugin-directory-guidelines` | wordpress.org plugin directory rules (naming, GPL, Plugin Check) |
| `wp-rest-api` | REST routes, schemas, permission callbacks |
| `wp-abilities-api` | Registering abilities (`wp_register_ability`) and their REST exposure |
| `wp-abilities-audit` | Auditing a plugin's REST surface for abilities to add |
| `wp-abilities-verify` | Checking abilities against their annotations, permissions and schemas |
| `wp-ability-development` | Designing what an ability accepts, returns and does (its schemas and behaviour) |
| `wp-block-themes` | Block themes: `theme.json`, templates, template parts, style variations (Ollie is one) |
| `wp-patterns` | Block patterns and starter pages |
| `wp-block-development` | Custom blocks: `block.json`, rendering, deprecations |
| `wp-interactivity-api` | Front-end behaviour with `data-wp-*` directives |
| `wpds` | WordPress Design System components and tokens |
| `wp-performance` | Profiling, queries, autoloaded options, object cache, cron |
| `wp-phpstan` | PHPStan for WordPress code |
| `wp-wpcli-and-ops` | WP-CLI and site operations |
| `wp-env` | Local WordPress with `@wordpress/env` |
| `wp-playground` | WordPress Playground runs and previews |
| `blueprint` | Playground Blueprint JSON |

To update, run `.github/scripts/sync-wp-skills.sh` from this repository (it clones the latest WordPress/agent-skills, or pass the path of a checkout). It replaces every upstream skill, sets the commit above and prints any skill that is new (add a row to the table) or was removed upstream. The **Sync WordPress skills** workflow runs it every Monday and opens a pull request when something changed. Don't edit the copies; change them upstream.
