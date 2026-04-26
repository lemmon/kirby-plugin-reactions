<?php

declare(strict_types=1);

namespace Lemmon\Reactions;

use Kirby\Cms\Page;
use Kirby\Filesystem\F;
use Kirby\Http\Cookie;
use Throwable;

/**
 * Emoji reactions widget -- configurable reactions, append-only events, privacy-first storage.
 *
 * Public API: handle(), token(), validateToken(), pool(), active(), counts().
 * Page identity is the Kirby page UUID string from `$page->uuid()->toString()` (e.g. `page://...`),
 * not the content path / id.
 */
class Reactions
{
    private const CACHE_NAMESPACE = 'lemmon.reactions';
    private const STORAGE_FILENAME = 'events.jsonl';
    private const SESSION_VISITOR_KEY = 'reactions.visitor';

    private const TOKEN_TTL = 1800; // 30 min
    private const RATE_PER_IP = 120;
    private const RATE_WINDOW = 600; // 10 min
    private const RATE_PER_IP_PAGE = 80;
    private const RATE_PAGE_WINDOW = 86_400; // 24 h
    private const COUNTS_CACHE_TTL = 300; // 5 min
    private const ACTIVE_CACHE_TTL = 60; // 1 min
    private const IPV4_PREFIX = 24; // /24
    private const IPV6_PREFIX = 64; // /64

    private const ACTION_ON = 'on';
    private const ACTION_OFF = 'off';

    // Exposed for snippet defaults / i18n fallback.
    public const DEFAULT_QUESTION = 'React to this page';
    public const DEFAULT_CONFIRMATION = 'Reaction saved.';
    public const DEFAULT_POOL = [
        'up' => [
            'emoji' => '👍',
            'label' => 'Thumbs up',
        ],
        'down' => [
            'emoji' => '👎',
            'label' => 'Thumbs down',
        ],
    ];

    private static ?string $requestVisitorId = null;

    // --- Public API ------------------------------------------------------

    /**
     * Handle POST to the /reactions route.
     *
     * Every failure mode returns the same shape (re-render via HTMX,
     * redirect otherwise) so probing POSTs can't distinguish outcomes.
     */
    public static function handle(): mixed
    {
        $request = \kirby()->request();
        $data = $request->data();
        $pageUri = self::str($data['page'] ?? '');
        $token = self::str($data['token'] ?? '');
        $reaction = self::str($data['reaction'] ?? '');
        $page = $pageUri !== '' ? \page($pageUri) : null;
        $isHtmx = strtolower((string) $request->header('HX-Request')) === 'true';

        if (!$page || !self::isKnownReaction($reaction) || !self::validateToken($token, $pageUri)) {
            return self::respond($isHtmx, $page);
        }

        $ipHash = self::currentIpHash();

        if ($ipHash === '' || !self::checkRateLimits($pageUri, $ipHash)) {
            return self::respond($isHtmx, $page);
        }

        $visitorHash = self::visitorHash($pageUri, true);

        if ($visitorHash === '') {
            return self::respond($isHtmx, $page);
        }

        $action = array_key_exists($reaction, self::activeForVisitor($pageUri, $visitorHash))
            ? self::ACTION_OFF
            : self::ACTION_ON;

        self::storeEvent($pageUri, $reaction, $action, $visitorHash);

        return self::respond($isHtmx, $page, self::confirmation());
    }

    /**
     * The configured reaction pool, keyed by stable reaction id.
     *
     * @return array<string, array{emoji: string, label: string}>
     */
    public static function pool(): array
    {
        $configured = \option('lemmon.reactions.pool', self::DEFAULT_POOL);

        if (!is_array($configured)) {
            return self::DEFAULT_POOL;
        }

        $reactions = [];

        foreach ($configured as $key => $entry) {
            if (!is_string($key) || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $key) !== 1) {
                continue;
            }

            if (!is_string($entry) && !is_array($entry)) {
                continue;
            }

            $label = self::labelFromKey($key);
            $emoji = '';

            if (is_string($entry)) {
                $emoji = trim($entry);
            }

            if (is_array($entry)) {
                $emoji = self::str($entry['emoji'] ?? '');
                $label = self::str($entry['label'] ?? $label);
            }

            if ($emoji === '') {
                continue;
            }

            $reactions[$key] = [
                'emoji' => $emoji,
                'label' => $label !== '' ? $label : self::labelFromKey($key),
            ];
        }

        return $reactions !== [] ? $reactions : self::DEFAULT_POOL;
    }

    /**
     * Aggregate active reaction counts for a page UUID string (`$page->uuid()->toString()`).
     *
     * Counts are derived by replaying the append-only event log and keeping
     * only the final on/off state for each page-scoped anonymous visitor + reaction pair.
     *
     * @return array<string, int>
     */
    public static function counts(string $pageUri): array
    {
        $empty = self::emptyCounts();

        if ($pageUri === '') {
            return $empty;
        }

        $cache = self::cache();
        $key = self::countsKey($pageUri);
        $hit = $cache->get($key);

        if (is_array($hit)) {
            return self::normalizeCounts($hit);
        }

        $counts = self::readCounts($pageUri);
        $cache->set($key, $counts, (int) ceil(self::COUNTS_CACHE_TTL / 60));

        return $counts;
    }

    /**
     * Active reactions for the current visitor on a page UUID string.
     *
     * @return array<string, bool>
     */
    public static function active(string $pageUri): array
    {
        if ($pageUri === '') {
            return [];
        }

        $visitorHash = self::visitorHash($pageUri, false);

        if ($visitorHash !== '') {
            return self::activeForVisitor($pageUri, $visitorHash);
        }

        return [];
    }

    /**
     * Issue a signed, timestamped token for a page UUID string.
     *
     * Compact form: "<payload>.<signature>" (both base64url). Payload is
     * a JSON object { page, issuedAt, nonce } where `page` is `page://...`.
     * Throws if random_bytes() is unavailable.
     */
    public static function token(string $pageUri): string
    {
        $payload = json_encode([
            'page' => $pageUri,
            'issuedAt' => time(),
            'nonce' => bin2hex(random_bytes(16)),
        ]);

        if ($payload === false) {
            return '';
        }

        $encoded = self::b64url($payload);

        return $encoded . '.' . self::sign($encoded);
    }

    public static function validateToken(
        #[\SensitiveParameter]
        string $token,
        string $pageUri,
    ): bool {
        if ($token === '' || $pageUri === '' || substr_count($token, '.') !== 1) {
            return false;
        }

        [$encoded, $signature] = explode('.', $token);
        $json = self::b64urlDecode($encoded);

        if ($json === false) {
            return false;
        }

        $payload = json_decode($json, true);
        $signedPage = $payload['page'] ?? null;

        if (!is_string($signedPage) || $signedPage !== $pageUri) {
            return false;
        }

        $issuedAt = (int) ($payload['issuedAt'] ?? 0);

        if ($issuedAt <= 0 || (time() - $issuedAt) > self::TOKEN_TTL) {
            return false;
        }

        return hash_equals(self::sign($encoded), $signature);
    }

    // --- Config ----------------------------------------------------------

    private static function isKnownReaction(string $reaction): bool
    {
        return array_key_exists($reaction, self::pool());
    }

    private static function labelFromKey(string $key): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $key));
    }

    private static function confirmation(): string
    {
        $confirmation = \t('reactions.confirmation', self::DEFAULT_CONFIRMATION);

        return is_string($confirmation) && $confirmation !== '' ? $confirmation : self::DEFAULT_CONFIRMATION;
    }

    /** @return array<string, int> */
    private static function emptyCounts(): array
    {
        return array_fill_keys(array_keys(self::pool()), 0);
    }

    /**
     * @param array<mixed> $counts
     * @return array<string, int>
     */
    private static function normalizeCounts(array $counts): array
    {
        $normalized = self::emptyCounts();

        foreach ($normalized as $reaction => $count) {
            if (!array_key_exists($reaction, $counts)) {
                continue;
            }

            $normalized[$reaction] = max(0, (int) $counts[$reaction]);
        }

        return $normalized;
    }

    /**
     * @param array<mixed> $active
     * @return array<string, bool>
     */
    private static function normalizeActive(array $active): array
    {
        $known = self::pool();
        $normalized = [];

        foreach ($active as $key => $value) {
            $reaction = is_string($key) ? $key : self::str($value);

            if (array_key_exists($reaction, $known)) {
                $normalized[$reaction] = true;
            }
        }

        return $normalized;
    }

    // --- Tokens & session ------------------------------------------------

    private static function sign(string $payload): string
    {
        return self::b64url(hash_hmac('sha256', $payload, self::secret(), true));
    }

    private static function secret(): string
    {
        $override = \option('lemmon.reactions.secret');

        return is_string($override) && $override !== ''
            ? $override
            : \kirby()->contentToken(null, self::CACHE_NAMESPACE);
    }

    private static function visitorHash(string $pageUri, bool $create): string
    {
        $visitorId = self::visitorId($create);

        return $visitorId !== '' ? hash_hmac('sha256', $pageUri . '|' . $visitorId, self::secret()) : '';
    }

    private static function visitorId(bool $create): string
    {
        if (self::$requestVisitorId !== null) {
            return self::$requestVisitorId;
        }

        if ($create === false && !self::hasSessionCookie()) {
            return '';
        }

        $options = ['detect' => true];

        if ($create === true) {
            $options['long'] = true;
        }

        $session = \kirby()->session($options);
        $visitorId = $session->get(self::SESSION_VISITOR_KEY);

        if (is_string($visitorId) && preg_match('/^[a-f0-9]{32}$/', $visitorId) === 1) {
            return self::$requestVisitorId = $visitorId;
        }

        if ($create === false) {
            return '';
        }

        $visitorId = bin2hex(random_bytes(16));
        $session->set(self::SESSION_VISITOR_KEY, $visitorId);

        return self::$requestVisitorId = $visitorId;
    }

    private static function hasSessionCookie(): bool
    {
        $name = \option('session.cookieName', 'kirby_session');
        $name = is_string($name) && $name !== '' ? $name : 'kirby_session';

        return Cookie::get($name) !== null;
    }

    // --- Storage (JSONL) -------------------------------------------------

    private static function storageDir(): string
    {
        $override = \option('lemmon.reactions.storage.dir');

        if (is_string($override) && $override !== '') {
            return rtrim($override, '/\\');
        }

        $root = \kirby()->root('storage');

        if (!is_string($root) || $root === '') {
            $root = \kirby()->root('site') . '/storage';
        }

        return rtrim($root, '/\\') . '/reactions';
    }

    private static function storageFile(): string
    {
        return self::storageDir() . '/' . self::STORAGE_FILENAME;
    }

    /** @return resource|false */
    private static function openReadHandle(string $file)
    {
        set_error_handler(
            static fn(int $severity, string $message, string $filePath, int $line): bool => true,
        );

        try {
            return fopen($file, 'r');
        } finally {
            restore_error_handler();
        }
    }

    /** @return array<string, int> */
    private static function readCounts(string $pageUri): array
    {
        $file = self::storageFile();

        if (!is_file($file)) {
            return self::emptyCounts();
        }

        $handle = self::openReadHandle($file);

        if ($handle === false) {
            return self::emptyCounts();
        }

        $known = self::pool();
        $states = [];

        while (($line = fgets($handle)) !== false) {
            $entry = json_decode(trim($line), true);

            if (!is_array($entry) || ($entry['page'] ?? null) !== $pageUri) {
                continue;
            }

            $visitorHash = self::str($entry['visitorHash'] ?? '');
            $reaction = self::str($entry['reaction'] ?? '');
            $action = self::action($entry['action'] ?? null);

            if ($visitorHash === '' || !array_key_exists($reaction, $known) || $action === null) {
                continue;
            }

            $states[$visitorHash][$reaction] = $action;
        }

        fclose($handle);

        $counts = self::emptyCounts();

        foreach ($states as $visitor) {
            foreach ($visitor as $reaction => $action) {
                if ($action !== self::ACTION_ON) {
                    continue;
                }

                $counts[$reaction]++;
            }
        }

        return $counts;
    }

    /** @return array<string, bool> */
    private static function activeForVisitor(string $pageUri, string $visitorHash): array
    {
        if ($pageUri === '' || $visitorHash === '') {
            return [];
        }

        $cache = self::cache();
        $key = self::activeKey($pageUri, $visitorHash);
        $hit = $cache->get($key);

        if (is_array($hit)) {
            return self::normalizeActive($hit);
        }

        $active = self::readActive($pageUri, $visitorHash);
        $cache->set($key, array_keys($active), (int) ceil(self::ACTIVE_CACHE_TTL / 60));

        return $active;
    }

    /** @return array<string, bool> */
    private static function readActive(string $pageUri, string $visitorHash): array
    {
        $file = self::storageFile();

        if (!is_file($file)) {
            return [];
        }

        $handle = self::openReadHandle($file);

        if ($handle === false) {
            return [];
        }

        $known = self::pool();
        $active = [];

        while (($line = fgets($handle)) !== false) {
            $entry = json_decode(trim($line), true);

            if (
                !is_array($entry)
                || ($entry['page'] ?? null) !== $pageUri
                || ($entry['visitorHash'] ?? null) !== $visitorHash
            ) {
                continue;
            }

            $reaction = self::str($entry['reaction'] ?? '');
            $action = self::action($entry['action'] ?? null);

            if (!array_key_exists($reaction, $known) || $action === null) {
                continue;
            }

            if ($action !== self::ACTION_ON) {
                unset($active[$reaction]);
                continue;
            }

            $active[$reaction] = true;
        }

        fclose($handle);

        return $active;
    }

    private static function storeEvent(
        string $pageUri,
        string $reaction,
        string $action,
        string $visitorHash,
    ): void {
        $entry = [
            'page' => $pageUri,
            'reaction' => $reaction,
            'action' => $action,
            'timestamp' => time(),
            'visitorHash' => $visitorHash,
        ];

        $payload = json_encode($entry);

        if ($payload === false) {
            return;
        }

        try {
            F::append(self::storageFile(), $payload . PHP_EOL);
            self::cache()->remove(self::countsKey($pageUri));
            self::cache()->remove(self::activeKey($pageUri, $visitorHash));
        } catch (Throwable $exception) {
            error_log(
                'lemmon.reactions: failed to persist reaction event (' . get_debug_type($exception) . ')',
            );
        }
    }

    private static function action(mixed $value): ?string
    {
        $action = self::str($value);

        return match ($action) {
            self::ACTION_ON, self::ACTION_OFF => $action,
            default => null,
        };
    }

    // --- Rate limiting ---------------------------------------------------

    private static function checkRateLimits(string $pageUri, string $ipHash): bool
    {
        if ($ipHash === '') {
            return false;
        }

        if (!self::allowBucket('rate.ip.' . $ipHash, self::RATE_PER_IP, self::RATE_WINDOW)) {
            return false;
        }

        $key = 'rate.ip_page.' . $ipHash . '.' . hash('sha1', $pageUri);

        return self::allowBucket($key, self::RATE_PER_IP_PAGE, self::RATE_PAGE_WINDOW);
    }

    private static function allowBucket(string $key, int $limit, int $windowSeconds): bool
    {
        $cache = self::cache();
        $now = time();
        $bucket = $cache->get($key);

        if (!is_array($bucket) || ($bucket['reset'] ?? 0) <= $now) {
            $bucket = ['count' => 0, 'reset' => $now + $windowSeconds];
        }

        if ($bucket['count'] >= $limit) {
            return false;
        }

        $bucket['count']++;
        $cache->set($key, $bucket, (int) ceil(($bucket['reset'] - $now) / 60));

        return true;
    }

    /**
     * Resolve our Kirby cache bucket.
     *
     * `kirby()->cache('lemmon.reactions')` is mapped to the plugin option
     * `cache` by AppCaches::cacheOptionsKey() -- configured in index.php.
     */
    private static function cache()
    {
        return \kirby()->cache(self::CACHE_NAMESPACE);
    }

    private static function countsKey(string $pageUri): string
    {
        return 'counts.' . hash('sha1', $pageUri);
    }

    private static function activeKey(string $pageUri, string $visitorHash): string
    {
        return 'active.' . hash('sha1', $pageUri) . '.' . hash('sha1', $visitorHash);
    }

    // --- HTTP response ---------------------------------------------------

    private static function respond(bool $isHtmx, ?Page $page, ?string $status = null): mixed
    {
        if ($isHtmx) {
            return (
                $page
                    ? (string) \snippet(
                        'reactions',
                        [
                            'page' => $page,
                            'status' => $status,
                        ],
                        true,
                    )
                    : ''
            );
        }

        return \go($page ? $page->url() : \site()->url());
    }

    // --- IP handling -----------------------------------------------------

    private static function currentIpHash(): string
    {
        return self::hashIp(self::clientIp());
    }

    private static function clientIp(): ?string
    {
        $ip = \kirby()->visitor()->ip();

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    private static function hashIp(?string $ip): string
    {
        $normalized = self::anonymizeIp($ip);

        return $normalized === null ? '' : hash_hmac('sha256', $normalized, self::secret());
    }

    /**
     * Anonymize an IP to /24 (IPv4) or /64 (IPv6).
     *
     * Both prefixes fall on byte boundaries, so we can use trivial
     * byte-level operations instead of bitmasking math.
     */
    private static function anonymizeIp(?string $ip): ?string
    {
        $ip = is_string($ip) ? trim($ip) : '';

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts = array_slice($parts, 0, (int) (self::IPV4_PREFIX / 8));

            while (count($parts) < 4) {
                $parts[] = '0';
            }

            return implode('.', $parts);
        }

        $packed = inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        $bytes = (int) (self::IPV6_PREFIX / 8);

        $normalized = inet_ntop(substr($packed, 0, $bytes) . str_repeat("\0", 16 - $bytes));

        return $normalized !== false ? $normalized : null;
    }

    // --- Utilities -------------------------------------------------------

    private static function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $value): string|false
    {
        $pad = strlen($value) % 4;

        if ($pad !== 0) {
            $value .= str_repeat('=', 4 - $pad);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    private static function str(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
