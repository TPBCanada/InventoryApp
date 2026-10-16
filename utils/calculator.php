<!---------------------------------->
  <!------ Calculator Button ----->
  <!---------------------------------->

  <aside id="calcDrawer" class="calc-drawer" aria-hidden="true" role="dialog" aria-label="Quick Calculator">
    <div class="calc-drawer__panel">
      <header class="calc-drawer__hdr">
        <h2>Quick Calculator</h2>
        <button class="calc-drawer__close" id="calcCloseBtn" aria-label="Close">&times;</button>
      </header>

      <section class="calc-drawer__body">
        <div class="calc-shell" aria-label="Calculator">
          <div id="calcDisplay" class="calc-display" role="status" aria-live="polite" tabindex="0">0</div>

          <div class="calc-grid" id="calcGrid">
            <button type="button" class="calc-btn is-utility" data-action="clear">C/E</button>
            <button type="button" class="calc-btn is-utility" data-action="backspace" aria-label="Backspace">Bksp</button>
            <button type="button" class="calc-btn is-utility" data-action="sign">+/-</button>
            <button type="button" class="calc-btn is-operator" data-action="operator" data-operator="divide">/</button>

            <button type="button" class="calc-btn" data-action="digit" data-value="7">7</button>
            <button type="button" class="calc-btn" data-action="digit" data-value="8">8</button>
            <button type="button" class="calc-btn" data-action="digit" data-value="9">9</button>
            <button type="button" class="calc-btn is-operator" data-action="operator" data-operator="multiply">*</button>

            <button type="button" class="calc-btn" data-action="digit" data-value="4">4</button>
            <button type="button" class="calc-btn" data-action="digit" data-value="5">5</button>
            <button type="button" class="calc-btn" data-action="digit" data-value="6">6</button>
            <button type="button" class="calc-btn is-operator" data-action="operator" data-operator="minus">-</button>

            <button type="button" class="calc-btn" data-action="digit" data-value="1">1</button>
            <button type="button" class="calc-btn" data-action="digit" data-value="2">2</button>
            <button type="button" class="calc-btn" data-action="digit" data-value="3">3</button>
            <button type="button" class="calc-btn is-operator" data-action="operator" data-operator="plus">+</button>

            <button type="button" class="calc-btn span-2" data-action="digit" data-value="0">0</button>
            <button type="button" class="calc-btn" data-action="dot">.</button>
            <button type="button" class="calc-btn is-equals" data-action="equals">=</button>

            <button type="button" class="calc-btn is-utility" data-action="unary" data-unary="reciprocal">1/x</button>
            <button type="button" class="calc-btn is-utility" data-action="unary" data-unary="sqrt">sqrt</button>
            <button type="button" class="calc-btn is-utility" data-action="unary" data-unary="square">x^2</button>
            <button type="button" class="calc-btn is-operator" data-action="operator" data-operator="power">y^x</button>
          </div>
        </div>
      </section>
    </div>
    <button class="calc-drawer__backdrop" id="calcBackdrop" aria-label="Close"></button>
  </aside>
