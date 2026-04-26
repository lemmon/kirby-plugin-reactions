<?php

declare(strict_types=1);

use Lemmon\Reactions\Reactions;

/**
 * @var \Kirby\Cms\Page|null $page
 * @var string|null $question
 * @var string|null $confirmation
 * @var string|null $status
 */

$page ??= page();

if (!$page) {
    return;
}

// Kirby page UUID (`page://...`); stable across folder renames, unlike `$page->id()`.
$pageUri = $page->uuid()->toString();

// i18n via Kirby translations; English defaults when no translation exists.
// Per-call overrides win (passed as snippet parameters).
$question ??= t('reactions.question', Reactions::DEFAULT_QUESTION);
$confirmation ??= t('reactions.confirmation', Reactions::DEFAULT_CONFIRMATION);
$status ??= null;

$reactions = Reactions::pool();
$counts = Reactions::counts($pageUri);
$active = Reactions::active($pageUri);
$action = url('reactions');
$token = Reactions::token($pageUri);

if ($token === '' || $reactions === []) {
    return;
}
?>
<form class="reactions" method="POST" action="<?= esc($action, 'attr') ?>" hx-post="<?= esc(
    $action,
    'attr',
) ?>" hx-target="this" hx-swap="outerHTML">
    <input type="hidden" name="page" value="<?= esc($pageUri, 'attr') ?>">
    <input type="hidden" name="token" value="<?= esc($token, 'attr') ?>">

    <p class="reactions__question"><?= esc($question) ?></p>

    <div class="reactions__actions">
        <?php foreach ($reactions as $key => $reaction):
            $isActive = array_key_exists($key, $active);
            $count = (int) ($counts[$key] ?? 0);
            $classes = 'reactions__button' . ($isActive ? ' reactions__button--active' : '');
            $aria = $reaction['label'] . ', ' . $count . ' ' . ($count === 1 ? 'vote' : 'votes');
            ?>
        <button
            type="submit"
            name="reaction"
            value="<?= esc($key, 'attr') ?>"
            class="<?= esc($classes, 'attr') ?>"
            aria-pressed="<?= $isActive ? 'true' : 'false' ?>"
            aria-label="<?= esc($aria, 'attr') ?>"
        >
            <span class="reactions__emoji" aria-hidden="true"><?= esc($reaction['emoji']) ?></span>
            <span class="reactions__label"><?= esc($reaction['label']) ?></span>
            <span class="reactions__count"><?= $count ?></span>
        </button>
        <?php endforeach ?>
    </div>

    <?php if (is_string($status) && $status !== ''): ?>
    <p class="reactions__status" role="status" aria-live="polite"><?= esc($status) ?></p>
    <?php endif ?>
</form>
