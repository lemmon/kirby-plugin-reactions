<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Reactions.php';

use Lemmon\Reactions\Reactions;

Kirby::plugin('lemmon/reactions', [
    'options' => [
        'secret' => null,
        'storage' => [
            'dir' => null,
        ],
        // Kirby resolves `kirby()->cache('lemmon.reactions')` to this option
        // key via AppCaches::cacheOptionsKey(): no cache subname means the
        // option key is plain `cache`. The explicit `prefix` skips Kirby's
        // default `{indexUrl-slug}/lemmon/reactions` so HTTP and CLI don't
        // end up with separate cache directories.
        'cache' => [
            'active' => true,
            'type' => 'file',
            'prefix' => 'lemmon/reactions',
        ],
    ],
    'snippets' => [
        'reactions' => __DIR__ . '/snippets/reactions.php',
    ],
    'routes' => [
        [
            'pattern' => 'reactions',
            'method' => 'POST',
            // Not `Reactions::handle(...)`: Kirby invokes route actions with Closure::call($route); static callables cannot be bound (PHP 9).
            // @mago-expect lint:prefer-first-class-callable
            'action' => fn(): mixed => Reactions::handle(),
        ],
    ],
]);
