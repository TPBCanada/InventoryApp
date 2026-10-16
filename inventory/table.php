<section class="card card--pad">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;">
        <h3 style="margin:0;"><?= $status === 'required' ? 'Counts Required' : ($status === 'recent' ? 'Previously Counted' : 'Products') ?></h3>
        <span class="text-muted"><?= number_format(count($counts)) ?> result<?= count($counts) === 1 ? '' : 's' ?></span>
    </div>

    <?php if (!$counts): ?>
        <p class="text-muted">No products match this view.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="count-table">
                <thead>
                    <tr>
                        <th>SKU</th><th>Description</th><th>Status</th><th>Locations</th>
                        <th>System total</th><th>Requested</th><th>Last count</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($counts as $row): ?>
                    <tr>
                        <td data-label="SKU">
                            <a href="invSku.php?sku_q=<?= rawurlencode((string) $row['sku_num']) ?>">
                                <strong><?= h($row['sku_num']) ?></strong>
                            </a>
                            <?php if (!empty($row['shelf'])): ?><div class="text-muted">Shelf: <?= h($row['shelf']) ?></div><?php endif; ?>
                        </td>
                        <td data-label="Description"><?= h($row['desc']) ?></td>
                        <td data-label="Status">
                            <?php if ((int) $row['count_required'] === 1): ?>
                                <span class="count-badge count-badge--required">Required</span>
                            <?php elseif (!empty($row['last_manual_count_at'])): ?>
                                <span class="count-badge count-badge--complete">Counted</span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td data-label="Locations"><?= number_format((int) $row['location_count']) ?></td>
                        <td data-label="System total"><?= number_format((int) $row['system_total']) ?></td>
                        <td data-label="Requested">
                            <?= $row['count_requested_at'] ? h(date('M j, Y g:i A', strtotime((string) $row['count_requested_at']))) : '—' ?>
                        </td>
                        <td data-label="Last count">
                            <?php if ($row['last_manual_count_at']): ?>
                                <?= h(date('M j, Y g:i A', strtotime((string) $row['last_manual_count_at']))) ?>
                                <?php if ($row['last_manual_count_total'] !== null): ?>
                                    <div class="text-muted">Observed: <?= number_format((int) $row['last_manual_count_total']) ?></div>
                                <?php endif; ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td data-label="Actions">
                            <div class="count-actions">
                            <?php if ((int) $row['count_required'] === 1): ?>
                                <?php if ($can_complete_counts): ?>
                                <form method="post" class="count-complete-form">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                                    <input type="hidden" name="action" value="complete">
                                    <input type="hidden" name="sku_id" value="<?= (int) $row['id'] ?>">
                                    <input class="input" type="number" min="0" step="1" name="observed_total" placeholder="Total (optional)">
                                    <button class="btn btn--primary" type="submit">Complete</button>
                                </form>
                                <?php endif; ?>
                                <?php if ($can_request_counts): ?>
                                <form method="post" onsubmit="return confirm('Cancel this count request?');">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="sku_id" value="<?= (int) $row['id'] ?>">
                                    <button class="btn btn-outline" type="submit">Cancel</button>
                                </form>
                                <?php endif; ?>
                            <?php elseif ($can_request_counts): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                                    <input type="hidden" name="action" value="request">
                                    <input type="hidden" name="sku_id" value="<?= (int) $row['id'] ?>">
                                    <button class="btn btn-outline" type="submit">Request count</button>
                                </form>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
