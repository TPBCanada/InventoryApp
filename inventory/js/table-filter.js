/**
 * Generic function to filter table rows based on user input.
 * @param {string} filterInputId - The ID of the input field (e.g., 'skuFilter').
 * @param {string} tableId - The ID of the table to filter (e.g., 'srcTable').
 * @param {number} columnIndex - The zero-based index of the column to search (e.g., 0 for SKU).
 */
function setupTableFilter(filterInputId, tableId, columnIndex) {
    const filterInput = document.getElementById(filterInputId);
    const tableBody = document.querySelector(`#${tableId} tbody`);

    if (!filterInput || !tableBody) {
        console.warn(`Table filter setup failed: Missing input '${filterInputId}' or table '${tableId}'.`);
        return;
    }

    const filterFunction = () => {
        const filterText = filterInput.value.toLowerCase();
        const rows = tableBody.querySelectorAll('tr');

        rows.forEach(row => {
            // Get the cell in the specified column index (td:nth-child(index + 1))
            const targetCell = row.querySelector(`td:nth-child(${columnIndex + 1})`);
            
            // Handle placeholder rows (like 'No items' or 'Loading...')
            const isPlaceholder = targetCell ? targetCell.classList.contains('muted') : true;

            if (isPlaceholder) {
                // Always show placeholder rows or rows where the target cell isn't found
                row.style.display = '';
            } else {
                const cellText = targetCell.textContent.toLowerCase();
                if (cellText.includes(filterText)) {
                    row.style.display = ''; // Show
                } else {
                    row.style.display = 'none'; // Hide
                }
            }
        });
    };

    // Listen for input changes
    filterInput.addEventListener('input', filterFunction);

    // Call it once on setup in case a filter was preserved or page reloads with data
    filterFunction();
    
    // Return the function so pages can call it again after an AJAX reload
    return filterFunction;
}