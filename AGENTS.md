# Plugin Guidelines

## Purpose & Scope

- Configurable emoji reactions widget. Plain HTML POST; no JS required. HTMX-progressive if HTMX is on the page.
- One global reaction pool for now, configured via `lemmon.reactions.reactions`.
- Visitors can cast multiple reactions per page and click an active reaction again to uncast it.
- Never write to page content files. The JSONL event log under `storage/` is the sole source of truth.

## Project Structure

- `index.php` -- plugin registration, options, snippet, POST route.
- `src/Reactions.php` -- single static class: config, tokens, rate limiting, storage replay, counts, active visitor state, IP anonymization.
- `snippets/reactions.php` -- form markup. Copy via `t('reactions.*')` with English fallbacks; per-call overrides via snippet params.
- `README.md` -- end-user docs and configuration examples.

## PHP Style

- PSR-12: four-space indent; brace on the next line for classes/methods.
- All state is static; `Reactions` is a utility class, never instantiated.
- Defaults as `private const UPPER_SNAKE_CASE`. Snippet label fallbacks and default reaction pool are `public const`.
- ASCII punctuation in code, comments, and docs (`--` instead of em dashes). Emoji are allowed in reaction config examples and defaults.
- Early returns; avoid nested boolean gymnastics.

## Security & Privacy

- Always hash IPs; never store raw IPs. IPv4 truncated to /24 and IPv6 to /64 before HMAC-SHA256.
- Persistent events store a page-scoped HMAC of a random session visitor id, not an IP hash.
- HMAC-SHA256 signed tokens with a 30-min TTL; `hash_equals()` for signature comparison.
- Two rate-limit tiers (per-IP, per-IP-per-page). There is no duplicate blocker because toggling is legitimate behavior.
- Every failure mode of `handle()` returns the same response shape as success so probing POSTs can't distinguish outcomes.
- The append-only event log is authoritative; IP hashes only live in ephemeral rate-limit cache buckets.

## Storage & Caching

- Log path resolution: `lemmon.reactions.storage.dir` override -> Kirby's `storage` root + `/reactions` -> `{site-root}/storage/reactions` fallback.
- Log format: one JSON object per line at `events.jsonl`, with `page`, `reaction`, `action`, `timestamp`, and `visitorHash`.
- Counts replay the log and keep only the final on/off state for each page-scoped anonymous visitor + reaction pair.
- `Reactions::counts($page->uuid()->toString())` memoises in Kirby's cache for 5 min. Successful events invalidate the page counts cache.
- `Reactions::active($page->uuid()->toString())` memoises current visitor state for 1 min. Successful events invalidate that visitor/page entry.
- Cache config key is `cache`, not `lemmon.reactions.cache`, for the same Kirby plugin-cache reason documented in `index.php`.

## Request Flow

1. Snippet renders buttons, counts, active state, and a signed token.
2. POST `/reactions` -> validate token, validate reaction key, rate-limit by anonymized IP cache bucket, compute current visitor state from the page-scoped session visitor hash, append `on` or `off` event, invalidate caches.
3. HTMX clients get the re-rendered widget; everyone else gets a 302 back to the page.
