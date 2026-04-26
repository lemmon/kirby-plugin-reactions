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

Override the prompt per call:

```php
<?php snippet('reactions', [
    'question' => 'How did this land?',
]) ?>
```

Labels read from `t('reactions.*')` with English fallbacks:

```yaml
reactions.question: React to this page
reactions.confirmation: Reaction saved.
```

BEM-like classes for styling: `reactions`, `reactions__question`, `reactions__actions`, `reactions__button`, `reactions__button--active`, `reactions__emoji`, `reactions__label`, `reactions__count`, `reactions__status`. No CSS ships with the plugin.

## Reaction pool

The plugin has one global reaction pool:

```php
return [
    'lemmon.reactions' => [
        'pool' => [
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
    ],
];
```

Keys are stable ids and must match `[a-z0-9][a-z0-9_-]{0,63}`. Store and compare the key, not the emoji, so labels and emoji can change later without losing old data.

For very small configs, a string value is accepted as shorthand:

```php
'lemmon.reactions' => [
    'pool' => [
        'up' => '👍',
        'down' => '👎',
    ],
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

Clicking an active reaction appends an `off` event. Counts replay the log and keep the final `on` / `off` state for each visitor + reaction pair.

Votes key on `$page->uuid()->toString()`, so data survives renames. Requires UUIDs (Kirby's default).

The log path resolves in this order:

1. `lemmon.reactions.storage.dir` override -- wins if set.
2. `{storage-root}/reactions/` if the site registers a `storage` root.
3. `{site-root}/storage/reactions/` as the zero-config fallback.

### Register a shared `storage` root (recommended)

Register a Git-ignored `storage` root once in `public/index.php`:

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

## Programmatic API

Three static helpers on `Lemmon\Reactions\Reactions`, all expecting `$page->uuid()->toString()`:

```php
use Lemmon\Reactions\Reactions;

$pageUri = $page->uuid()->toString();

Reactions::counts($pageUri); // ['up' => 42, 'down' => 3]
Reactions::active($pageUri); // ['up' => true]
Reactions::pool();           // ['up' => ['emoji' => '...', 'label' => '...'], ...]
```

## HTMX (optional)

The form always renders with `hx-post`, `hx-target="this"`, and `hx-swap="outerHTML"` attributes. They are ignored when HTMX is not loaded; when it is, clicking a reaction swaps the form in place with updated counts and pressed states.

## Configuration

```php
return [
    'lemmon.reactions' => [
        'secret' => null, // HMAC secret; falls back to Kirby's content token
        'storage' => [
            'dir' => null, // absolute path override
        ],
        'pool' => [
            'up' => [
                'emoji' => '👍',
                'label' => 'Thumbs up',
            ],
            'down' => [
                'emoji' => '👎',
                'label' => 'Thumbs down',
            ],
        ],
        'cache' => [
            'active' => true,
            'type' => 'file',
            'prefix' => 'lemmon/reactions',
        ],
    ],
];
```

`cache` is a standard Kirby cache config; switch `type` for Redis/memcached. The explicit `prefix` keeps HTTP and CLI invocations sharing one cache directory.

To hide the widget, remove `snippet('reactions')` from your templates.

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

Set a strong secret in production. Generate one with `openssl rand -hex 32` (or `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`) and paste the resulting hex string as a literal value, or load it from env:

```php
'lemmon.reactions' => [
    'secret' => $_ENV['REACTIONS_SECRET'] ?? null,
],
```

Do not embed `bin2hex(random_bytes(32))` directly in the config -- it would re-run per request and rotate the secret every time. Rotating the secret invalidates outstanding tokens and changes the HMAC for every visitor going forward.

Vote events store a page-scoped HMAC of a random session visitor id, keeping reaction state separate per page. IPs are anonymized to /24 (IPv4) or /64 (IPv6), then HMAC-hashed for ephemeral rate-limit buckets only -- never persisted.

## License

MIT. See `LICENSE`.
