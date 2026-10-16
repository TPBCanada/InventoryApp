<section class="count-summary" aria-label="Inventory count summary">
    <div class="card count-stat">
        <div class="count-stat__value"><?= number_format((int) $summary['required']) ?></div>
        <div class="count-stat__label">Counts required</div>
    </div>
    <div class="card count-stat">
        <div class="count-stat__value">
            <?= $summary['oldest_request'] ? h(date('M j, Y', strtotime((string) $summary['oldest_request']))) : '—' ?>
        </div>
        <div class="count-stat__label">Oldest request</div>
    </div>
    <div class="card count-stat">
        <div class="count-stat__value"><?= number_format((int) $summary['completed_30_days']) ?></div>
        <div class="count-stat__label">Counted in last 30 days</div>
    </div>
</section>
