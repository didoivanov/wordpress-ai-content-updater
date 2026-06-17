# AI Content Rewriter

WordPress plugin that rewrites pages, posts and custom post types (including ACF Pro flexible content / repeaters) using the Anthropic Claude API. Preview & approve every field before it's written back.

## Features

- **Anthropic Claude integration** via the Messages API (`x-api-key`, version `2023-06-01`).
- **Settings page** for API key, model (`claude-sonnet-4-5`, `claude-opus-4-5`, `claude-haiku-4-5`, etc.), max tokens, temperature.
- **Prompts**:
  - System prompt
  - Global rewriting prompt
  - Per post type prompts (auto-discovered from registered post types — pages, posts, and every CPT)
  - Per-post extra instructions (textarea in the edit-screen meta box)
- **Field scope**:
  - Post title (optional)
  - Post content
  - Excerpt (optional)
  - ACF Pro fields: `text`, `textarea`, `wysiwyg`, `url`, `email`
  - Walks `flexible_content`, `repeater`, and `group` recursively, preserving structure
- **Preview & approve** workflow:
  - Generate preview → side-by-side diff for each field
  - Edit the rewritten value before applying
  - Approve only the fields you want
- **Self-update from GitHub** releases (no third-party update server needed).
- Nonce-protected AJAX, capability checks (`edit_post`, `manage_options`).

## Installation

1. Download the latest zip from [Releases](https://github.com/didoivanov/wordpress-ai-content-updater/releases).
2. WordPress admin → Plugins → Add New → Upload Plugin.
3. Activate, then visit **Settings → AI Content Rewriter** and add your Anthropic API key.

## How it works

The plugin builds a JSON document of every selected field (post fields + ACF fields, with stable ids that encode their origin), sends it to Claude with strict instructions to return the same shape, and writes the response back to its origin (via `wp_update_post` for post fields and `update_field` / `update_sub_field` for ACF). Preview data is stored in a transient server-side so the apply step cannot be spoofed by the client.

## Releasing a new version (auto-updates)

1. Bump the version in `ai-content-rewriter.php` header and in `AICR_VERSION`.
2. Update `readme.txt` `Stable tag` and changelog.
3. Tag and push: `git tag v0.2.0 && git push --tags`.
4. Create a GitHub Release for the tag. Attach a zip named `ai-content-rewriter.zip` whose root contains the `ai-content-rewriter/` folder. If you skip the asset, the plugin falls back to GitHub's source zip and the updater renames the folder automatically.

A helper script is provided:

```bash
./bin/build-zip.sh 0.2.0
```

That produces `dist/ai-content-rewriter.zip` for the GitHub release.

## ACF path format

Internally each ACF field gets a path like:

- `field_name` – top-level
- `group_name.sub_name` – inside a group
- `repeater[0].sub_name` – inside a repeater
- `flex_field[2|layout_name].sub_name` – inside a flexible content layout

These paths are what the rewriter writes back to via `update_sub_field`.

## Roadmap

- Bulk rewrite from the post list table
- WP-CLI command
- Multilingual / WPML aware rewriting
- Per-field overrides for prompts
- Optional streaming preview
