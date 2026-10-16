<?php
declare(strict_types=1);
?>

<div id="barcode-modal" class="bc-modal" hidden>
  <div class="bc-modal__overlay" data-bc-close></div>
  <div class="bc-modal__card card card--pad">
    <div class="bc-modal__header">
      <div>
        <div class="small muted">Manage Barcodes for</div>
        <strong class="bc-sku"></strong>
      </div>
      <button type="button" class="btn btn--ghost" data-bc-close>&times;</button>
    </div>

    <form id="bc-add-form" class="bc-form" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8') ?>">
      <div class="bc-form__row">
        <input class="input" name="upc" placeholder="UPC / barcode" required>
        <select class="select" name="uom_label" required>
          <option value="UNIT">UNIT</option>
          <option value="CARTON">CARTON</option>
          <option value="MASTERCASE">MASTERCASE</option>
          <option value="PALLET">PALLET</option>
        </select>
        <input class="input" name="multiplier" type="number" min="1" value="1" style="width:110px;">
        <button class="btn btn--primary" type="submit">Add / Update</button>
      </div>
    </form>

    <div class="bc-list">
      <div class="bc-rows"></div>
    </div>
  </div>
</div>
