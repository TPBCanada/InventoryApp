<?php
declare(strict_types=1);

?>

<div class="lot__modal" id="globalLotModal" aria-hidden="true" style="display:none;">
  <div class="lot__menu">
    <header class="lot__header">
      <h3 class="modal-title" id="lotModalTitle">Lot Management</h3>
      <button type="button" class="btn--close-modal lot__menu-close">Close</button>
    </header>

    <div class="existing-lots">
        <div id="lot-expiry-hint" class="lot-expiry-hint" role="status" aria-live="polite" hidden></div>
        <div class="lots-table-header">
          <span>Lot Number</span>
          <span>Expiry</span>
          <span>On Hand</span>
          <span>Quantity</span>
          <span>Action</span>
        </div>
        <div id="lots-list-container"></div>
      </div>

    <div class="lot__content">
      <form class="lot__form" id="lotManagerForm" novalidate>
        <input type="hidden" id="modal_sku_id" name="sku_id" value="">
        <input type="hidden" id="modal_sku_num" name="sku_num" value="">
        <input type="hidden" id="modal_lot_required" name="lot_required" value="0">
        <input type="hidden" id="modal_loc_id" name="loc_id" value="">
        <div class="form-group">
          <label for="lotCode">Lot Code</label>
          <input id="lotCode" name="lot_code" class="input" type="text" placeholder="LOT1234" required style="text-transform: uppercase;">
        </div>
        <div class="form-group">
          <label for="lotExpiry">Expiry Date</label>
          <input id="lotExpiry" name="expiry_date" class="input" type="date">
        </div>
        <div class="form-group">
          <label for="lotQty">Quantity</label>
          <input id="lotQty" name="quantity" class="input" type="number" placeholder="0" min="0">
        </div>

        <div class="lot__form-actions">
          <button class="btn--action add" type="submit" id="saveLotBtn">Create</button>
        </div>
      </form>      
    </div>
  </div>
</div>
