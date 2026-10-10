<?php
/*
 * Breathing and grounding exercises with simple timers. Not behind the Mind PIN:
 * it's linked from Help now. The timers are in app.js ([data-breathe], [data-ground]).
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();

render_header('Breathe', 'help');
?>
<div class="page-head">
    <h1>Breathe and ground</h1>
    <a class="btn btn-small btn-help" href="<?= h(url('help.php')) ?>">Help now</a>
</div>

<section class="card breathe-card" id="breathe">
    <h2>Box breathing</h2>
    <p class="muted">Breathe in for 4, hold for 4, out for 4, hold for 4. A few minutes can slow things down.</p>
    <div class="breathe" data-breathe="4,4,4,4" aria-live="polite">
        <div class="breathe-circle" aria-hidden="true"></div>
        <p class="breathe-phase">Ready when you are</p>
        <p class="breathe-count muted"></p>
        <span class="inline-actions">
            <button class="btn btn-primary" type="button" data-breathe-start>Start</button>
            <button class="btn" type="button" data-breathe-stop hidden>Stop</button>
        </span>
        <label class="check small"><input type="radio" name="pattern" value="4,4,4,4" checked> Box (4-4-4-4)</label>
        <label class="check small"><input type="radio" name="pattern" value="4,7,8,0"> Calming (in 4, hold 7, out 8)</label>
    </div>
    <noscript><p>Breathe in slowly through your nose for 4, hold for 4, breathe out through your mouth for 4, hold for 4. Repeat for a few minutes.</p></noscript>
</section>

<section class="card" id="ground">
    <h2>5-4-3-2-1 grounding</h2>
    <p class="muted">Bring yourself back to the present by noticing what's around you. Take your time with each step.</p>
    <ol class="ground-steps" data-ground>
        <li><strong>5 things you can see.</strong> Look around and name them, out loud or in your head.</li>
        <li><strong>4 things you can touch.</strong> Your clothes, the chair, the floor under your feet.</li>
        <li><strong>3 things you can hear.</strong> Near or far away.</li>
        <li><strong>2 things you can smell.</strong> Or two smells you like.</li>
        <li><strong>1 thing you can taste.</strong> Or one good thing about yourself.</li>
    </ol>
    <span class="inline-actions" hidden data-ground-controls>
        <button class="btn" type="button" data-ground-prev>Back</button>
        <button class="btn btn-primary" type="button" data-ground-next>Next</button>
    </span>
</section>
<?php render_footer();
