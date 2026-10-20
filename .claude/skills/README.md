# WordPress agent skills

These are copies of skills from [WordPress/agent-skills](https://github.com/WordPress/agent-skills) (GPL-2.0-or-later),
taken at commit `55cd0f78b996f453c194a1a6ecc5c9a2e0f6cf15`. They teach coding agents WordPress's own conventions; Claude Code loads them from this folder.

Skills here: wp-playground, blueprint, wp-plugin-development, wp-rest-api, wp-abilities-api, wp-abilities-verify, wp-wpcli-and-ops.

To update, from a checkout of WordPress/agent-skills:

```shell
for s in wp-playground blueprint wp-plugin-development wp-rest-api wp-abilities-api wp-abilities-verify wp-wpcli-and-ops; do rm -rf .claude/skills/$s && cp -R ../agent-skills/skills/$s .claude/skills/; done
```

Then update the commit above. Don't edit the copies; change them upstream.
