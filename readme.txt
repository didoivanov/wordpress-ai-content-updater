=== AI Content Rewriter ===
Contributors: didoivanov
Tags: ai, anthropic, claude, content, rewriter, acf
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Rewrite WordPress pages, posts and custom post types (including ACF Pro flexible content / repeaters) with Anthropic Claude. Preview & approve before applying.

== Description ==

* Per post-type prompts plus a global prompt and a per-post prompt override.
* Preview each field side-by-side with the original, tweak before applying.
* ACF Pro support: flexible content layouts, repeaters, groups, text/textarea/wysiwyg.
* Configurable model (Sonnet, Opus, Haiku), max tokens, temperature.
* Self-updates from GitHub releases.

== Installation ==

1. Upload the `ai-content-rewriter` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Visit Settings → AI Content Rewriter and add your Anthropic API key.

== Changelog ==

= 0.4.1 =
* New: "Check for updates" link on the Plugins screen (action links + row meta).
* New: "Check for updates" button on the Configuration page, next to the version number.
* New: Admin notice confirms whether you are up to date, an update is available, or the check failed.

= 0.4.0 =
* Fix: "Empty response from Anthropic (stop_reason: max_tokens)" error on long fields.
* New: Default max tokens raised from 4096 to 16000 (Claude Sonnet/Opus 4.x support much larger outputs).
* New: Per-post-type max tokens override, next to the per-type prompt.
* New: Auto-retry on truncated responses with 2x token budget (capped at 64000). Toggle in Configuration.
* Fix: Partial (truncated) responses are now treated as failures instead of silently saving half-rewritten content.
* UI: Live progress log now shows the max tokens setting used per field.
* UI: Friendlier error messages when output is truncated, with a hint to raise the limit.

= 0.3.0 =
* New: Top-level admin menu "AI Rewriter" with Configuration + Costs subpages.
* New: Token usage + cost tracking (per call, per model, per post type, daily).
* New: Configurable pricing table (USD per 1M tokens).
* New: Live progress log on the edit screen using Server-Sent Events. Shows: collecting fields → field N/M → sending → received with tokens & cost → done.
* New: Per-field timing, token counts, and cost shown inline.

= 0.2.0 =
* Fix: "Could not parse model response as JSON" error on Gutenberg/ACF HTML content.
* Switched to Anthropic tool-use (structured output) so the model can no longer return malformed JSON or prose.
* Per-field API calls: every selected field is rewritten in its own request, so one failure no longer breaks the whole batch.
* UI: shows per-field error banner when a single field fails; other fields still apply.
* Improved HTML prompting: explicit instruction to preserve Gutenberg block comments (<!-- wp:... -->).
* Increased HTTP timeout to 180s for long content.

= 0.1.0 =
* Initial release.
