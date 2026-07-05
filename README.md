# Craft MCP

A **Craft CMS 5** plugin that exposes an in-process **MCP (Model Context Protocol)** server at
`POST /mcp`, so AI agents (Claude & co.) can control Craft — content, structure and system — as
fully as possible, bounded by a token-bound Craft user's own permissions.

- **Pure PHP, in-process.** No Node runtime, no shell-out. One `composer require`.
- **Auth = a Craft user.** A bearer token maps to a Craft user; every tool call runs as that user
  and respects Craft's permissions (`can()` checks). Structural/system operations additionally
  require an admin user.
- **Safety gating.** Tools are tiered `read` / `content-write` / `schema-write` / `system-write`.
  Schema- and system-writes require an explicit `"confirm": true` argument and are covered by
  master kill-switches in the plugin settings.

## Install

```bash
composer require gerry3010/craft-mcp
php craft plugin/install mcp
```

During development, add a path repository to the Craft project's `composer.json`:

```json
"repositories": [{ "type": "path", "url": "../craft-mcp", "options": { "symlink": true } }]
```

## Create a token

```bash
php craft mcp/tokens/create --user=agent@example.com --label="Claude" --ttl-days=90
php craft mcp/tokens/list
php craft mcp/tokens/revoke <id>
```

Recommendation: create a dedicated Craft user (e.g. an “AI Agent” user) in a user group whose
permissions match what the agent should be allowed to do, rather than an admin. Admin is only
needed for schema/system tools.

## Connect a client

Claude Code / Warp reach the HTTP endpoint through `mcp-remote`:

```bash
npx -y mcp-remote@latest https://your-site.example/mcp \
  --header "Authorization: Bearer <token>"
```

Claude Code `settings.json`:

```json
{
  "mcpServers": {
    "craft": {
      "command": "npx",
      "args": ["-y", "mcp-remote@latest", "https://your-site.example/mcp",
               "--header", "Authorization: Bearer <token>"]
    }
  }
}
```

## Tool surface

Call `describe_content_model` first — the client needs no hardcoded handles.

| Domain | Tools | Tier |
|---|---|---|
| Introspection | `describe_content_model`, `list_sections` | read |
| Entries | `list_entries`, `get_entry`, `create_entry`, `update_entry`, `delete_entry` | read / content-write |
| Matrix blocks | `list_blocks`, `add_block`, `update_block`, `move_block`, `delete_block` | read / content-write |
| Categories / Tags | `list_categories`, `get_category`, `create_category`, `update_category`, `delete_category`, `list_tags`, `create_tag`, `delete_tag` | read / content-write |
| Assets | `list_assets`, `list_asset_folders`, `upload_asset`, `delete_asset` | read / content-write |
| Globals | `list_globals`, `get_globals`, `update_globals` | read / content-write |
| Fields | `list_fields`, `get_field`, `save_field`, `delete_field` | read / schema-write |
| Structure | `save_entry_type`, `delete_entry_type`, `save_section`, `delete_section` | schema-write |
| Users | `list_users`, `get_user`, `list_user_groups`, `create_user`, `update_user`, `delete_user` | read / system-write |
| Project config | `project_config_get`, `project_config_set`, `project_config_apply` | read / system-write |
| Plugins | `list_plugins`, `install_plugin`, `uninstall_plugin`, `enable_plugin`, `disable_plugin` | read / system-write |
| GraphQL | `graphql` | read |
| Maintenance | `clear_caches`, `queue_status`, `run_gc` | content-/system-write |

**Matrix** fields are nested entries: manage their blocks with `ownerId` (the owner element id) and
`field` (the Matrix field handle). This works uniformly for entry, category and global-set Matrix
fields, and for nested Matrix (a block inside a block, where `ownerId` is the parent block id).

**Gating.** `schema-write` and `system-write` tools do nothing unless called with `"confirm": true`.
Read the current state first (`describe_content_model`, `get_field`, `project_config_get`) as a
dry-run, then re-call with `confirm`. The `project_config_*` tools are the low-level, fully generic
structural-control surface; `project_config_set` requires `allowAdminChanges` to be enabled.

## License

[MIT](LICENSE).
