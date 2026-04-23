# Reactions for Kirby

A privacy-friendly emoji reactions widget for Kirby CMS. Plain HTML POST, no JavaScript required. HTMX progressively enhances if present.

Visitors can cast multiple reactions on a page and click an active reaction again to remove it. Counts are always visible and represent the current active state, not the number of raw clicks.

## Installation

```bash
composer require lemmon/kirby-reactions
# or
git submodule add git@github.com:lemmon/kirby-plugin-reactions.git site/plugins/reactions
```

## Usage

```php
<?php snippet('reactions') ?>
```

Override copy per call when needed:

```php
<?php snippet('reactions', [
    'question' => 'How did this land?',
]) ?>
```

Labels read from `t('reactions.*')` with English fallbacks. Add translation keys for i18n instead of per-call overrides:

```yaml
reactions.question: React to this page
reactions.confirmation: Reaction saved.
```

BEM-like classes for styling: `reactions`, `reactions__question`, `reactions__actions`, `reactions__button`, `reactions__button--active`, `reactions__emoji`, `reactions__label`, `reactions__count`, `reactions__status`. No CSS ships with the plugin.

## Configure Reactions

The plugin has one global reaction pool:

```php
return [
    'lemmon.reactions.reactions' => [
        'up' => [
            'emoji' => '👍',
            'label' => 'Thumbs up',
        ],
        'down' => [
            'emoji' => '👎',
            'label' => 'Thumbs down',
        ],
        'heart' => [
            'emoji' => '❤️',
            'label' => 'Love it',
        ],
        'mindblown' => [
            'emoji' => '🤯',
            'label' => 'Mind blown',
        ],
    ],
];
```

Keys are stable ids and must match `[a-z0-9][a-z0-9_-]{0,63}`. Store and compare the key, not the emoji, so labels and emoji can change later without losing old data.

For very small configs, a string value is accepted as shorthand:

```php
'lemmon.reactions.reactions' => [
    'up' => '👍',
    'down' => '👎',
],
```

## Storage

Every valid click is appended to `{storage-root}/reactions/events.jsonl` as one JSON object per line:

```json
{
  "page": "page://...",
  "reaction": "up",
  "action": "on",
  "timestamp": 1710000000,
  "visitorHash": "..."
}
```

Clicking an active reaction appends an `off` event. Counts are derived by replaying the log and keeping the final `on` / `off` state for each page-scoped anonymous visitor + reaction pair:

```php
use Lemmon\Reactions\Reactions;

$totals = Reactions::counts($page->uuid()->toString());
// => ['up' => 42, 'down' => 3]
```

This keeps storage append-only and auditable. The tradeoff is that rapid toggling writes more lines, but rate limits cap growth and counts do not inflate. Add log compaction later if a site receives enough interaction volume to justify it.

Votes are keyed by the full page UUID string (`page://...` from `$page->uuid()->toString()`), not the filesystem path, so data survives renames. UUIDs are enabled in Kirby by default; if you have turned them off globally, this plugin is not a fit.

Counts are cached for 5 minutes; the entry for a page is invalidated after each successful event. A visitor's active reaction state is cached for 1 minute and invalidated after their own event.

The log path resolves in this order:

1. `lemmon.reactions.storage.dir` override -- wins if set.
2. `{storage-root}/reactions/` if the site registers a `storage` root.
3. `{site-root}/storage/reactions/` as the zero-config fallback.

### Register a shared `storage` root (recommended)

The `storage/` directory is intended as a universal, Git-ignored area for runtime-only plugin data. Register it once in `public/index.php`:

```php
$kirby = new Kirby([
    'roots' => [
        'index'   => __DIR__,
        'base'    => $base = dirname(__DIR__),
        'site'    => $base . '/site',
        'content' => $base . '/content',
        'storage' => $base . '/storage',
    ],
]);
```

Then add `/storage` to `.gitignore`.

## HTMX (optional)

The form always renders with `hx-post`, `hx-target="this"`, and `hx-swap="outerHTML"` attributes. They are ignored when HTMX is not loaded; when it is, clicking a reaction swaps the form in place with updated counts and pressed states.

## Configuration

```php
return [
    'lemmon.reactions.secret'      => null, // HMAC secret; falls back to Kirby's content token
    'lemmon.reactions.storage.dir' => null, // absolute path override
    'lemmon.reactions.reactions'   => [
        'up' => [
            'emoji' => '👍',
            'label' => 'Thumbs up',
        ],
        'down' => [
            'emoji' => '👎',
            'label' => 'Thumbs down',
        ],
    ],
    'lemmon.reactions.cache'       => [
        'active' => true,
        'type'   => 'file',
        'prefix' => 'lemmon/reactions',
    ],
];
```

The `cache` option is the plugin-cache config key Kirby resolves for `kirby()->cache('lemmon.reactions')` (see `AppCaches::cacheOptionsKey`). The explicit `prefix` bypasses Kirby's default `{indexUrl-slug}/lemmon/reactions` path, so caches stay in one place across CLI and HTTP invocations. Redis/memcached users only need to change `type` + driver options.

To turn the widget off: remove `snippet('reactions')` from your templates, or disable the whole plugin in `site/config` with `'lemmon/reactions' => false` (Kirby does not load the plugin, so the route and snippet are both gone).

### Baked-in defaults

| Behavior           | Value                                      |
| ------------------ | ------------------------------------------ |
| Token TTL          | 30 min                                     |
| Rate limit (IP)    | 120 events / 10 min                        |
| Rate limit (page)  | 80 events per IP + page / 24 h             |
| Counts cache TTL   | 5 min                                      |
| Active cache TTL   | 1 min                                      |
| IPv4 anonymization | /24 (last octet zeroed)                    |
| IPv6 anonymization | /64 (last 8 bytes zeroed)                  |
| Visitor identity   | random session id, page-scoped HMAC in log |
| IP hash            | ephemeral cache buckets only, not in JSONL |
| Log filename       | `events.jsonl`                             |
| User agent         | not stored                                 |

## Security

Set a strong secret in production:

```php
'lemmon.reactions.secret' => bin2hex(random_bytes(32)),
// or: openssl rand -hex 32
```

If the secret leaks, attackers can forge tokens and bypass rate limiting. Load it from env / secret manager; never commit it.

Persistent vote events store a page-scoped HMAC of a random session visitor id. This keeps reaction state separate per page and avoids linking a visitor's reactions across pages from the JSONL log alone.

IP addresses are only used for ephemeral rate-limit cache buckets. They are anonymized to /24 for IPv4 and /64 for IPv6 before being HMAC-hashed. Raw IPs, persistent IP hashes, and user agents are not stored in the event log.

## License

MIT. See `LICENSE`.
