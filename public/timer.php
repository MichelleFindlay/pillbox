<?php
/*
 * A visual timer: a ring that empties as time passes, for a task, a break, or waiting for a
 * medicine to kick in. Runs in the browser; nothing is saved.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
render_header('Timer', 'today');
?>
<section class="card timer" data-timer>
    <h1>Timer</h1>
    <div class="timer-ring" aria-hidden="true">
        <svg viewBox="0 0 120 120"><circle class="timer-track" cx="60" cy="60" r="52"/><circle class="timer-fill" cx="60" cy="60" r="52" pathLength="100" stroke-dasharray="100 100" data-timer-ring/></svg>
        <span class="timer-time" data-timer-time>5:00</span>
    </div>
    <p class="sr" aria-live="polite" data-timer-status></p>
    <div class="preset-list" role="group" aria-label="Length">
        <?php foreach ([1, 2, 5, 10, 15, 25, 45] as $m): ?><button class="btn btn-small" type="button" data-timer-set="<?= $m * 60 ?>"><?= $m ?> min</button><?php endforeach; ?>
    </div>
    <label class="narrow">Your own (minutes) <input type="number" min="1" max="240" class="count-input" data-timer-custom></label>
    <span class="inline-actions">
        <button class="btn btn-primary" type="button" data-timer-start>Start</button>
        <button class="btn" type="button" data-timer-pause hidden>Pause</button>
        <button class="btn" type="button" data-timer-reset>Reset</button>
    </span>
    <p class="muted small">When it ends the ring flashes, and your phone vibrates if it can. Keep this page open.</p>
</section>
<?php render_footer();
