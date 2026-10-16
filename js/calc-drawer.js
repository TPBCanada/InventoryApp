(() => {
  const drawer = document.getElementById('calcDrawer');
  const panel = drawer?.querySelector('.calc-drawer__panel');
  const backdrop = document.getElementById('calcBackdrop');
  const launch = document.getElementById('calcLaunchBtn');
  const closeBtn = document.getElementById('calcCloseBtn');
  const display = document.getElementById('calcDisplay');
  const grid = document.getElementById('calcGrid');

  if (!drawer || !panel || !launch || !display || !grid) return;

  const state = {
    displayValue: '0',
    firstOperand: null,
    waitingForSecondOperand: false,
    operator: null,
  };

  const formatNumber = (n) => {
    if (!Number.isFinite(n)) return 'Error';
    const str = n.toString();
    if (str.length <= 12) return str;
    const trimmed = n.toPrecision(12);
    return Number(trimmed).toString();
  };

  const updateDisplay = () => {
    display.textContent = state.displayValue;
  };

  const resetAll = () => {
    state.displayValue = '0';
    state.firstOperand = null;
    state.waitingForSecondOperand = false;
    state.operator = null;
    updateDisplay();
  };

  const handleError = () => {
    state.displayValue = 'Error';
    state.firstOperand = null;
    state.waitingForSecondOperand = false;
    state.operator = null;
    updateDisplay();
  };

  const inputDigit = (digit) => {
    if (state.displayValue === 'Error') resetAll();
    if (state.waitingForSecondOperand) {
      state.displayValue = digit;
      state.waitingForSecondOperand = false;
    } else {
      state.displayValue = state.displayValue === '0' ? digit : state.displayValue + digit;
    }
    updateDisplay();
  };

  const inputDot = () => {
    if (state.displayValue === 'Error') resetAll();
    if (state.waitingForSecondOperand) {
      state.displayValue = '0.';
      state.waitingForSecondOperand = false;
    } else if (!state.displayValue.includes('.')) {
      state.displayValue += '.';
    }
    updateDisplay();
  };

  const backspace = () => {
    if (state.displayValue === 'Error') return resetAll();
    if (state.waitingForSecondOperand) {
      state.displayValue = '0';
      state.waitingForSecondOperand = false;
      updateDisplay();
      return;
    }
    const next = state.displayValue.length > 1 ? state.displayValue.slice(0, -1) : '0';
    state.displayValue = next;
    updateDisplay();
  };

  const toggleSign = () => {
    if (state.displayValue === 'Error') return resetAll();
    state.displayValue = state.displayValue.startsWith('-')
      ? state.displayValue.slice(1)
      : state.displayValue === '0'
        ? state.displayValue
        : '-' + state.displayValue;
    updateDisplay();
  };

  const performCalc = (first, second, op) => {
    switch (op) {
      case 'plus': return first + second;
      case 'minus': return first - second;
      case 'multiply': return first * second;
      case 'divide': return second === 0 ? Infinity : first / second;
      case 'power': return Math.pow(first, second);
      default: return second;
    }
  };

  const handleOperator = (nextOp) => {
    const inputValue = parseFloat(state.displayValue);
    if (Number.isNaN(inputValue)) return handleError();

    if (state.operator && state.waitingForSecondOperand) {
      state.operator = nextOp;
      return;
    }

    if (state.firstOperand === null) {
      state.firstOperand = inputValue;
    } else if (state.operator) {
      const result = performCalc(state.firstOperand, inputValue, state.operator);
      if (!Number.isFinite(result)) return handleError();
      state.displayValue = formatNumber(result);
      state.firstOperand = result;
      updateDisplay();
    }

    state.waitingForSecondOperand = true;
    state.operator = nextOp;
  };

  const handleEquals = () => {
    if (!state.operator) return;
    const second = parseFloat(state.displayValue);
    if (Number.isNaN(second)) return handleError();

    const result = performCalc(state.firstOperand ?? 0, second, state.operator);
    if (!Number.isFinite(result)) return handleError();

    state.displayValue = formatNumber(result);
    state.firstOperand = null;
    state.operator = null;
    state.waitingForSecondOperand = false;
    updateDisplay();
  };

  const handleUnary = (fn) => {
    if (state.displayValue === 'Error') return resetAll();
    const current = parseFloat(state.displayValue);
    if (!Number.isFinite(current)) return handleError();

    let result = current;
    switch (fn) {
      case 'reciprocal':
        if (current === 0) return handleError();
        result = 1 / current;
        break;
      case 'sqrt':
        if (current < 0) return handleError();
        result = Math.sqrt(current);
        break;
      case 'square':
        result = current * current;
        break;
      default:
        return;
    }

    state.displayValue = formatNumber(result);
    if (!state.operator) {
      state.firstOperand = result;
    }
    state.waitingForSecondOperand = false;
    updateDisplay();
  };

  const setOpen = (isOpen) => {
    if (isOpen) {
      drawer.setAttribute('data-open', 'true');
      drawer.setAttribute('aria-hidden', 'false');
      launch.setAttribute('aria-expanded', 'true');
      document.body.dataset.calcOpen = 'true';
      setTimeout(() => display.focus({ preventScroll: true }), 10);
    } else {
      drawer.removeAttribute('data-open');
      drawer.setAttribute('aria-hidden', 'true');
      launch.setAttribute('aria-expanded', 'false');
      delete document.body.dataset.calcOpen;
    }
  };

  const toggleDrawer = () => {
    const isOpen = drawer.getAttribute('data-open') === 'true';
    setOpen(!isOpen);
  };

  grid.addEventListener('click', (evt) => {
    const btn = evt.target.closest('button[data-action]');
    if (!btn) return;
    const action = btn.dataset.action;

    switch (action) {
      case 'digit':
        inputDigit(btn.dataset.value || '0');
        break;
      case 'dot':
        inputDot();
        break;
      case 'clear':
        resetAll();
        break;
      case 'backspace':
        backspace();
        break;
      case 'sign':
        toggleSign();
        break;
      case 'operator':
        handleOperator(btn.dataset.operator);
        break;
      case 'equals':
        handleEquals();
        break;
      case 'unary':
        handleUnary(btn.dataset.unary);
        break;
      default:
        break;
    }
  });

  launch.addEventListener('click', toggleDrawer);
  backdrop?.addEventListener('click', () => setOpen(false));
  closeBtn?.addEventListener('click', () => setOpen(false));

  document.addEventListener('keydown', (evt) => {
    if (evt.key === 'Escape' && drawer.getAttribute('data-open') === 'true') {
      setOpen(false);
    }
  });

  updateDisplay();
})();
