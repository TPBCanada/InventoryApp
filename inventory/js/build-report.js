(function () {
  'use strict';

  const config = window.buildReportConfig || { tables: [], relations: [], defaultLimit: 500 };
  const tables = Array.isArray(config.tables) ? config.tables : [];
  const relations = Array.isArray(config.relations) ? config.relations : [];

  const launchBtn = document.getElementById('build-report-launch-btn');
  const modal = document.getElementById('build-report-modal');
  const searchWrap = document.getElementById('build-report-search-wrap');
  const modalToolbar = document.querySelector('.build-report-modal__toolbar');
  const schemaContainer = document.getElementById('build-report-schema');
  const searchInput = document.getElementById('build-report-search');
  const selectedContainer = document.getElementById('build-report-selected');
  const outputArea = document.getElementById('build-report-output');
  const insertBtn = document.getElementById('build-report-insert-btn');
  const copyBtn = document.getElementById('build-report-copy-btn');
  const brainBtn = document.getElementById('build-report-brain-btn');
  const expandBtn = document.getElementById('build-report-expand-btn');
  const collapseBtn = document.getElementById('build-report-collapse-btn');
  const brainOutput = document.getElementById('build-report-brain-output');
  const buildSqlBtn = document.getElementById('build-report-sync-btn');
  const joinToggle = document.getElementById('build-report-join');
  const andToggle = document.getElementById('build-report-and');
  const orToggle = document.getElementById('build-report-or');
  const havingToggle = document.getElementById('build-report-having');
  const joinTypeSelect = document.getElementById('build-report-join-type');
  const pickOrderInput = document.getElementById('build-report-order-number');
  const pickUsernameInput = document.getElementById('build-report-username');
  const pickProductInput = document.getElementById('build-report-product-id');
  const customPickOrderInput = document.getElementById('build-report-custom-order-number');
  const customPickUserIdInput = document.getElementById('build-report-custom-user-id');
  const customPickProductInput = document.getElementById('build-report-custom-product-id');
  const modalTitle = document.getElementById('build-report-modal-title');
  const modalSubtitle = document.querySelector('.build-report-modal__subtitle');
  const modePanels = Array.from(document.querySelectorAll('[data-report-mode-panel]'));
  const manualSql = document.getElementById('manual-sql');

  if (!modal || !schemaContainer || !outputArea) {
    return;
  }

  const relationIndex = relations.reduce((acc, relation) => {
    if (!relation || !relation.table || !relation.references_table) {
      return acc;
    }

    const key = `${relation.table}::${relation.references_table}`;
    acc.set(key, relation);
    return acc;
  }, new Map());

  let lastFocusedElement = null;
  let reportMode = 'custom-report';

  function escapeSelector(value) {
    const raw = String(value);
    if (window.CSS && typeof window.CSS.escape === 'function') {
      return window.CSS.escape(raw);
    }

    return raw.replace(/["\\]/g, '\\$&');
  }

  function quoteIdent(value) {
    return '`' + String(value).replace(/`/g, '``') + '`';
  }

  function formatAlias(tableName, index) {
    const base = String(tableName).replace(/[^a-zA-Z0-9]+/g, '_').replace(/^_+|_+$/g, '').toLowerCase() || 't';
    return `${base}_${index + 1}`;
  }

  function getTableSection(tableName) {
    return schemaContainer.querySelector(`[data-table-name="${escapeSelector(tableName)}"]`);
  }

  function getSelection() {
    const selection = [];

    tables.forEach((table) => {
      if (!table || !table.name) {
        return;
      }

      const tableSection = getTableSection(table.name);
      if (!tableSection) {
        return;
      }

      const tableCheckbox = tableSection.querySelector('[data-role="table-toggle"]');
      const columnCheckboxes = Array.from(tableSection.querySelectorAll('[data-role="column-toggle"]'));
      const checkedColumns = columnCheckboxes
        .filter((checkbox) => checkbox.checked)
        .map((checkbox) => checkbox.value);

      if (tableCheckbox && tableCheckbox.checked) {
        selection.push({
          name: table.name,
          columns: checkedColumns,
          allColumns: Array.isArray(table.columns) ? table.columns.map((column) => column.name) : [],
        });
      }
    });

    return selection;
  }

  function findRelation(leftTable, rightTable) {
    if (!leftTable || !rightTable) {
      return null;
    }

    const direct = relationIndex.get(`${leftTable}::${rightTable}`);
    if (direct) {
      return {
        direction: 'forward',
        relation: direct,
      };
    }

    const reverse = relationIndex.get(`${rightTable}::${leftTable}`);
    if (reverse) {
      return {
        direction: 'reverse',
        relation: reverse,
      };
    }

    return null;
  }

  function selectionHasJoinPath(selection) {
    if (selection.length < 2) {
      return false;
    }

    for (let index = 1; index < selection.length; index += 1) {
      let matched = false;

      for (let previous = index - 1; previous >= 0; previous -= 1) {
        if (findRelation(selection[previous].name, selection[index].name)) {
          matched = true;
          break;
        }
      }

      if (!matched) {
        return false;
      }
    }

    return true;
  }

  function buildJoinClause(sourceTable, sourceAlias, targetTable, targetAlias, joinType) {
    const relation = findRelation(sourceTable, targetTable);
    const joinKeyword = `${joinType} JOIN`;

    if (relation) {
      if (relation.direction === 'forward') {
        return `${joinKeyword} ${quoteIdent(targetTable)} ${targetAlias} ON ${sourceAlias}.${quoteIdent(relation.relation.column)} = ${targetAlias}.${quoteIdent(relation.relation.references_column)}`;
      }

      return `${joinKeyword} ${quoteIdent(targetTable)} ${targetAlias} ON ${targetAlias}.${quoteIdent(relation.relation.column)} = ${sourceAlias}.${quoteIdent(relation.relation.references_column)}`;
    }

    return `${joinKeyword} ${quoteIdent(targetTable)} ${targetAlias} ON 1 = 1 /* join condition */`;
  }

  function buildSelectList(selection) {
    const selectParts = [];

    selection.forEach((table, tableIndex) => {
      const alias = formatAlias(table.name, tableIndex);
      const columnNames = table.columns.length > 0 ? table.columns : table.allColumns;

      columnNames.forEach((columnName) => {
        selectParts.push(`${alias}.${quoteIdent(columnName)} AS ${quoteIdent(`${table.name}_${columnName}`)}`);
      });
    });

    if (selectParts.length === 0) {
      return '*';
    }

    return selectParts.join(',\n  ');
  }

  function buildFromClause(selection, joinEnabled) {
    if (selection.length === 0) {
      return '';
    }

    const first = selection[0];
    const firstAlias = formatAlias(first.name, 0);
    let lines = [`FROM ${quoteIdent(first.name)} ${firstAlias}`];

    for (let index = 1; index < selection.length; index += 1) {
      const current = selection[index];
      const currentAlias = formatAlias(current.name, index);

      if (joinEnabled) {
        let joinSourceTable = first.name;
        let joinSourceAlias = firstAlias;

        for (let previous = index - 1; previous >= 0; previous -= 1) {
          const candidate = selection[previous];
          if (!candidate) {
            continue;
          }

          const relation = findRelation(candidate.name, current.name);
          if (relation) {
            joinSourceTable = candidate.name;
            joinSourceAlias = formatAlias(candidate.name, previous);
            break;
          }
        }

        lines.push('  ' + buildJoinClause(joinSourceTable, joinSourceAlias, current.name, currentAlias, joinTypeSelect ? joinTypeSelect.value : 'INNER'));
      } else {
        lines[0] += `, ${quoteIdent(current.name)} ${currentAlias}`;
      }
    }

    return lines.join('\n');
  }

  function getTableAlias(tableName, index) {
    return formatAlias(tableName, index);
  }

  function buildColumnPredicate(table, columnName, alias) {
    const column = Array.isArray(table.columns)
      ? table.columns.find((item) => String(item.name || '') === String(columnName || ''))
      : null;
    const fullRef = `${alias}.${quoteIdent(columnName)}`;
    const type = String(column && column.data_type ? column.data_type : '').toLowerCase();

    if (!type) {
      return `${fullRef} IS NOT NULL`;
    }

    if (['char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'enum', 'set'].includes(type)) {
      return `TRIM(COALESCE(${fullRef}, '')) <> ''`;
    }

    return `${fullRef} IS NOT NULL`;
  }

  function buildWhereClause(selection) {
    const operator = andToggle && andToggle.checked
      ? 'AND'
      : (orToggle && orToggle.checked ? 'OR' : '');

    if (!operator) {
      return '';
    }

    const selectedPredicates = [];
    selection.forEach((table, index) => {
      const alias = getTableAlias(table.name, index);
      const columns = Array.isArray(table.columns) && table.columns.length > 0
        ? table.columns.map((column) => column.name)
        : [];

      columns.forEach((columnName) => {
        selectedPredicates.push(buildColumnPredicate(table, columnName, alias));
      });
    });

    if (selectedPredicates.length === 0) {
      return '';
    }

    return `WHERE\n  ${selectedPredicates.join(`\n  ${operator} `)}`;
  }

  function buildGroupClause(selection) {
    if (!havingToggle || !havingToggle.checked) {
      return '';
    }

    const groupingColumns = [];
    selection.forEach((table, index) => {
      const alias = getTableAlias(table.name, index);
      table.columns.slice(0, 1).forEach((columnName) => {
        groupingColumns.push(`${alias}.${quoteIdent(columnName)}`);
      });
    });

    if (groupingColumns.length === 0) {
      return 'HAVING COUNT(*) > 0';
    }

    return `GROUP BY ${groupingColumns.join(', ')}\nHAVING COUNT(*) > 0`;
  }

  function escapeSqlLiteral(value) {
    return String(value).trim().replace(/'/g, "''");
  }

  function getPickFilters(mode) {
    if (mode === 'custom-report') {
      return {
        orderNumber: String(customPickOrderInput && customPickOrderInput.value ? customPickOrderInput.value : '').trim(),
        userId: String(customPickUserIdInput && customPickUserIdInput.value ? customPickUserIdInput.value : '').trim(),
        productId: String(customPickProductInput && customPickProductInput.value ? customPickProductInput.value : '').trim(),
      };
    }

    return {
      orderNumber: String(pickOrderInput && pickOrderInput.value ? pickOrderInput.value : '').trim(),
      username: String(pickUsernameInput && pickUsernameInput.value ? pickUsernameInput.value : '').trim(),
      productId: String(pickProductInput && pickProductInput.value ? pickProductInput.value : '').trim(),
    };
  }

  function buildPickFilterSummary(filters, mode) {
    const parts = [];
    if (filters.orderNumber) {
      parts.push(`order # ${filters.orderNumber}`);
    }
    if (mode === 'custom-report') {
      if (filters.userId) {
        parts.push(`user id ${filters.userId}`);
      }
    } else if (filters.username) {
      parts.push(`username ${filters.username}`);
    }
    if (filters.productId) {
      parts.push(`product id ${filters.productId}`);
    }

    return parts.length > 0 ? parts.join(', ') : 'no filters';
  }

  function buildPickSql(mode) {
    const filters = getPickFilters(mode);
    const whereParts = ["pve.workflow_stage = 'pick'"];

    if (filters.orderNumber) {
      const value = escapeSqlLiteral(filters.orderNumber);
      whereParts.push(`po.order_number LIKE '%${value}%'`);
    }

    if (mode === 'custom-report' && filters.userId) {
      const value = escapeSqlLiteral(filters.userId);
      whereParts.push(`CAST(pve.user_id AS CHAR) LIKE '%${value}%'`);
    }

    if (mode !== 'custom-report' && filters.username) {
      const value = escapeSqlLiteral(filters.username);
      whereParts.push(`u.username LIKE '%${value}%'`);
    }

    if (filters.productId) {
      const value = escapeSqlLiteral(filters.productId);
      whereParts.push(`(pi.item_no LIKE '%${value}%' OR CAST(pve.pick_item_id AS CHAR) LIKE '%${value}%')`);
    }

    const customSelect = mode === 'custom-report'
      ? [
        '  pve.user_id,',
        '  u.username,',
      ]
      : [
        '  u.username,',
      ];

    return [
      'SELECT',
      '  po.order_number,',
      '  pi.item_no,',
      '  pve.event_type,',
      '  pve.expected_sku,',
      '  pve.qty_delta,',
      ...customSelect,
      '  pve.meta_json,',
      '  pve.created_at',
      'FROM pick_verify_events pve',
      'INNER JOIN pick_orders po ON pve.order_id = po.id',
      'INNER JOIN pick_items pi ON pve.pick_item_id = pi.id',
      'LEFT JOIN xserqhmy_users.users u ON pve.user_id = u.id',
      `WHERE ${whereParts.join('\n  AND ')}`,
      'ORDER BY pve.created_at ASC;',
    ].join('\n');
  }

  function buildSql(selection) {
    if (selection.length === 0) {
      return '-- Select one or more tables and columns to build a SQL draft.';
    }

    const joinEnabled = !!(joinToggle && joinToggle.checked && selection.length > 1);
    const selectList = buildSelectList(selection);
    const fromClause = buildFromClause(selection, joinEnabled);
    const whereClause = buildWhereClause(selection);
    const groupClause = buildGroupClause(selection);
    const limitValue = Number(config.defaultLimit || 50000);

    const pieces = [
      'SELECT',
      `  ${selectList}`,
      fromClause,
    ];

    if (whereClause) {
      pieces.push(whereClause);
    }

    if (groupClause) {
      pieces.push(groupClause);
    }

    pieces.push(`LIMIT ${Number.isFinite(limitValue) ? limitValue : 50000};`);

    return pieces.filter(Boolean).join('\n');
  }

  function syncControlState(selection) {
    const selectionCount = selection.length;
    const canJoin = selectionCount > 1;
    const autoJoin = canJoin && selectionHasJoinPath(selection);

    if (joinToggle) {
      joinToggle.disabled = !canJoin;
      if (!canJoin) {
        joinToggle.checked = false;
      } else if (autoJoin) {
        joinToggle.checked = true;
      }
    }

    if (joinTypeSelect) {
      joinTypeSelect.disabled = !canJoin || !(joinToggle && joinToggle.checked);
    }

    if (andToggle && andToggle.checked && orToggle) {
      orToggle.checked = false;
    }
    if (orToggle && orToggle.checked && andToggle) {
      andToggle.checked = false;
    }

    const hasClauseChoice = !!((andToggle && andToggle.checked) || (orToggle && orToggle.checked));
    if (!hasClauseChoice && havingToggle) {
      havingToggle.disabled = false;
    }
  }

  function buildBrainText(selection, sql) {
    if (selection.length === 0) {
      return [
        'AI Brain',
        'Select one or more tables and columns to build a custom report.',
        'Draft SQL:',
        sql,
      ].join('\n');
    }

    const tableNames = selection.map((table) => table.name).join(', ');
    const columnNames = selection
      .map((table) => table.columns.length > 0 ? table.columns.map((column) => `${table.name}.${column}`) : [`${table.name}.*`])
      .flat()
      .join(', ');
    const joinStatus = joinToggle && joinToggle.checked
      ? `JOIN mode is enabled with ${joinTypeSelect ? joinTypeSelect.value : 'INNER'} joins${selectionHasJoinPath(selection) ? ' and matching relationship metadata was found.' : '.'}`
      : 'JOIN mode is disabled, so the draft uses a simple multi-table FROM list.';
    const clauseNotes = [
      andToggle && andToggle.checked ? 'AND placeholders are enabled.' : '',
      orToggle && orToggle.checked ? 'OR placeholders are enabled.' : '',
      havingToggle && havingToggle.checked ? 'HAVING is enabled.' : '',
    ].filter(Boolean).join(' ');

    const missingJoins = [];
    for (let index = 1; index < selection.length; index += 1) {
      let matched = false;
      for (let previous = index - 1; previous >= 0; previous -= 1) {
        if (findRelation(selection[previous].name, selection[index].name)) {
          matched = true;
          break;
        }
      }
      if (!matched) {
        missingJoins.push(`${selection[index].name} needs a manual join condition.`);
      }
    }

    return [
      'AI Brain',
      `Tables: ${tableNames}.`,
      `Columns: ${columnNames}.`,
      joinStatus,
      clauseNotes || 'No optional clause placeholders were enabled.',
      missingJoins.length > 0 ? `Join hints: ${missingJoins.join(' ')}` : 'Join hints: all selected tables have direct relationship metadata or only one table was selected.',
      'Draft SQL:',
      sql,
    ].join('\n');
  }

  function renderSelected(selection) {
    selectedContainer.innerHTML = '';

    if (selection.length === 0) {
      const empty = document.createElement('span');
      empty.className = 'muted';
      empty.textContent = 'No tables selected yet.';
      selectedContainer.appendChild(empty);
      return;
    }

    selection.forEach((table) => {
      const chip = document.createElement('span');
      chip.className = 'build-report-builder__chip';
      const columnLabel = table.columns.length > 0 ? table.columns.length : table.allColumns.length;
      chip.textContent = `${table.name} (${columnLabel} column${columnLabel === 1 ? '' : 's'})`;
      selectedContainer.appendChild(chip);
    });
  }

  function renderPickSummary() {
    selectedContainer.innerHTML = '';

    const filters = getPickFilters(reportMode);
    const chips = [
      reportMode === 'custom-report' ? 'pick_verify_events custom report' : 'pick_verify_events base report',
      buildPickFilterSummary(filters, reportMode),
    ];

    chips.forEach((text) => {
      const chip = document.createElement('span');
      chip.className = 'build-report-builder__chip';
      chip.textContent = text;
      selectedContainer.appendChild(chip);
    });
  }

  function syncTableState(tableSection) {
    const tableCheckbox = tableSection.querySelector('[data-role="table-toggle"]');
    const columnCheckboxes = Array.from(tableSection.querySelectorAll('[data-role="column-toggle"]'));
    const checkedColumns = columnCheckboxes.filter((checkbox) => checkbox.checked);

    if (tableCheckbox) {
      tableCheckbox.checked = checkedColumns.length > 0;
      tableCheckbox.indeterminate = checkedColumns.length > 0 && checkedColumns.length < columnCheckboxes.length;
    }
  }

  function refreshPreview() {
    const selection = getSelection();
    syncControlState(selection);
    const sql = buildSql(selection);
    outputArea.value = sql;
    renderSelected(selection);

    if (brainOutput) {
      brainOutput.textContent = buildBrainText(selection, sql);
    }
  }

  function applySearchFilter() {
    const raw = String(searchInput && searchInput.value ? searchInput.value : '').trim().toLowerCase();

    schemaContainer.querySelectorAll('[data-table-name]').forEach((tableSection) => {
      const tableName = String(tableSection.getAttribute('data-table-name') || '').toLowerCase();
      const columnTexts = Array.from(tableSection.querySelectorAll('[data-column-name]')).map((node) => String(node.getAttribute('data-column-name') || '').toLowerCase());
      const matches = raw === '' || tableName.includes(raw) || columnTexts.some((text) => text.includes(raw));
      tableSection.hidden = !matches;
    });
  }

  function toggleColumnState(tableSection, isChecked) {
    const columnCheckboxes = Array.from(tableSection.querySelectorAll('[data-role="column-toggle"]'));
    columnCheckboxes.forEach((checkbox) => {
      checkbox.checked = isChecked;
    });
    syncTableState(tableSection);
  }

  function renderSchema() {
    schemaContainer.innerHTML = '';

    if (tables.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'muted';
      empty.textContent = 'No schema tables were found.';
      schemaContainer.appendChild(empty);
      return;
    }

    tables.forEach((table) => {
      const tableName = String(table.name || '');
      const columns = Array.isArray(table.columns) ? table.columns : [];

      const details = document.createElement('details');
      details.className = 'build-report-schema__group';
      details.setAttribute('data-table-name', tableName);
      details.removeAttribute('open');
      details.open = false;

      const summary = document.createElement('summary');

      const summaryLabel = document.createElement('span');
      summaryLabel.textContent = tableName;

      const summaryMeta = document.createElement('span');
      summaryMeta.className = 'build-report-schema__meta';
      summaryMeta.textContent = `${columns.length} column${columns.length === 1 ? '' : 's'}`;

      summary.appendChild(summaryLabel);
      summary.appendChild(summaryMeta);

      const columnsWrap = document.createElement('div');
      columnsWrap.className = 'build-report-schema__columns';

      const tableRow = document.createElement('label');
      tableRow.className = 'build-report-schema__column';

      const tableToggle = document.createElement('input');
      tableToggle.type = 'checkbox';
      tableToggle.setAttribute('data-role', 'table-toggle');
      tableToggle.addEventListener('change', () => {
        reportMode = 'custom';
        toggleColumnState(details, tableToggle.checked);
        refreshPreview();
      });

      const tableText = document.createElement('span');
      tableText.textContent = 'Select all columns';

      tableRow.appendChild(tableToggle);
      tableRow.appendChild(tableText);
      columnsWrap.appendChild(tableRow);

      columns.forEach((column) => {
        const columnRow = document.createElement('label');
        columnRow.className = 'build-report-schema__column';
        columnRow.setAttribute('data-column-name', String(column.name || ''));

        const columnToggle = document.createElement('input');
        columnToggle.type = 'checkbox';
        columnToggle.value = String(column.name || '');
        columnToggle.setAttribute('data-role', 'column-toggle');
        columnToggle.addEventListener('change', () => {
          reportMode = 'custom';
          syncTableState(details);
          refreshPreview();
        });

        const columnText = document.createElement('span');
        columnText.className = 'build-report-schema__column-name';
        columnText.textContent = column.name || '';

        const columnMeta = document.createElement('span');
        columnMeta.className = 'build-report-schema__meta';
        const flags = [];
        if (column.column_key === 'PRI') {
          flags.push('PK');
        }
        if (column.is_nullable === 'NO') {
          flags.push('required');
        }
        if (column.extra) {
          flags.push(column.extra);
        }
        columnMeta.textContent = flags.length > 0 ? `(${flags.join(', ')})` : column.data_type || '';

        const textWrap = document.createElement('span');
        textWrap.style.display = 'flex';
        textWrap.style.flexDirection = 'column';
        textWrap.appendChild(columnText);
        textWrap.appendChild(columnMeta);

        columnRow.appendChild(columnToggle);
        columnRow.appendChild(textWrap);
        columnsWrap.appendChild(columnRow);
      });

      details.appendChild(summary);
      details.appendChild(columnsWrap);
      schemaContainer.appendChild(details);
    });
  }

  function syncReportModeUi() {
    modePanels.forEach((panel) => {
      const panelMode = panel.getAttribute('data-report-mode-panel');
      const shouldShow = panelMode ? panelMode === reportMode : true;
      panel.hidden = !shouldShow;
      panel.style.display = shouldShow ? '' : 'none';
    });

    if (modalTitle) {
      modalTitle.textContent = 'Custom Report Builder';
    }

    if (modalSubtitle) {
      modalSubtitle.textContent = 'Select tables and columns, then build a custom report.';
    }

    if (modalToolbar) {
      modalToolbar.hidden = false;
      modalToolbar.style.display = '';
    }
    if (expandBtn) {
      expandBtn.hidden = false;
    }
    if (collapseBtn) {
      collapseBtn.hidden = false;
    }
    if (buildSqlBtn) {
      buildSqlBtn.hidden = false;
    }
  }

  function setAllTablesOpen(isOpen) {
    schemaContainer.querySelectorAll('details[data-table-name]').forEach((details) => {
      details.open = isOpen;
    });
  }

  function openModal() {
    reportMode = 'custom-report';
    if (modal.hidden) {
      lastFocusedElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    }

    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    modal.classList.add('is-open');
    document.body.style.overflow = 'hidden';

    if (window.InvApp && typeof window.InvApp.touchSession === 'function') {
      window.InvApp.touchSession();
    }

    window.requestAnimationFrame(() => {
      if (searchInput) {
        searchInput.focus();
      }
      syncReportModeUi();
      refreshPreview();
    });
  }

  function closeModal() {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    modal.hidden = true;
    document.body.style.overflow = '';

    if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
      lastFocusedElement.focus();
    } else if (launchBtn && typeof launchBtn.focus === 'function') {
      launchBtn.focus();
    }
  }

  function insertIntoEditor() {
    if (!manualSql) {
      return;
    }

    manualSql.value = outputArea.value || '';
    manualSql.dispatchEvent(new Event('input', { bubbles: true }));
    closeModal();
  }

  function copySql() {
    const text = outputArea.value || '';
    if (!navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
      outputArea.focus();
      outputArea.select();
      return;
    }

    navigator.clipboard.writeText(text).catch(() => {
      outputArea.focus();
      outputArea.select();
    });
  }

  renderSchema();
  refreshPreview();
  applySearchFilter();

  if (launchBtn) {
    launchBtn.addEventListener('click', openModal);
  }

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) {
      return;
    }

    if (target.matches('[data-build-report-close]')) {
      closeModal();
    }
  });

  if (searchInput) {
    searchInput.addEventListener('input', () => {
      applySearchFilter();
      refreshPreview();
    });
  }

  [pickOrderInput, pickUsernameInput, pickProductInput, customPickOrderInput, customPickUserIdInput, customPickProductInput].forEach((node) => {
    if (!node) {
      return;
    }
    node.addEventListener('input', () => {
      syncReportModeUi();
      refreshPreview();
    });
  });

  [joinToggle, andToggle, orToggle, havingToggle, joinTypeSelect].forEach((node) => {
    if (!node) {
      return;
    }
    node.addEventListener('change', () => {
      if (node === andToggle && andToggle && andToggle.checked && orToggle) {
        orToggle.checked = false;
      }

      if (node === orToggle && orToggle && orToggle.checked && andToggle) {
        andToggle.checked = false;
      }

      refreshPreview();
    });
  });

  if (buildSqlBtn) {
    buildSqlBtn.addEventListener('click', () => {
      reportMode = 'custom-report';
      syncReportModeUi();
      refreshPreview();
    });
  }

  if (brainBtn) {
    brainBtn.addEventListener('click', () => {
      refreshPreview();
      if (brainOutput) {
        brainOutput.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    });
  }

  if (expandBtn) {
    expandBtn.addEventListener('click', () => {
      reportMode = 'custom';
      syncReportModeUi();
      setAllTablesOpen(true);
    });
  }

  if (collapseBtn) {
    collapseBtn.addEventListener('click', () => {
      reportMode = 'custom';
      syncReportModeUi();
      setAllTablesOpen(false);
    });
  }

  if (insertBtn) {
    insertBtn.addEventListener('click', insertIntoEditor);
  }

  if (copyBtn) {
    copyBtn.addEventListener('click', copySql);
  }

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !modal.hidden) {
      closeModal();
    }
  });

  syncReportModeUi();
  window.buildReport = openModal;
})();
