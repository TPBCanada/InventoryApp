// Wrap existing logic in the IIFE
(function () {
    document.addEventListener('DOMContentLoaded', () => {
        const mappers = document.querySelectorAll('.column-mapper');
        const modal = document.getElementById('import-preview-modal');
        const backdrop = document.getElementById('import-preview-modal-backdrop');
        const closeButtons = document.querySelectorAll('.modal-close');
        
        // --- Modal Control Functions ---
        const showModal = () => {
            // Check for modal elements' existence before attempting to show them
            if (modal && backdrop) {
                modal.style.display = 'block';
                backdrop.style.display = 'block';
            }
        };

        const hideModal = () => {
            if (modal && backdrop) {
                modal.style.display = 'none';
                backdrop.style.display = 'none';
            }
        };

        // Attach hide listener to close buttons
        closeButtons.forEach(button => {
            button.addEventListener('click', hideModal);
        });

        // --- Existing Column Mapper Logic ---
        const updatePreview = (selectElement) => {
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            // Ensure .closest('td') is safe before accessing .previousElementSibling
            const selectParentCell = selectElement.closest('td');
            if (!selectParentCell) return; 
            
            const previewCell = selectParentCell.previousElementSibling;
            
            if (!previewCell) return;
            
            const previewSpan = previewCell.querySelector('.first-row-preview');

            previewSpan.style.fontStyle = 'normal';
            previewSpan.style.color = '#000'; 

            if (selectedOption.value === "") {
                previewSpan.textContent = '(Not Mapped)';
                previewSpan.style.fontStyle = 'italic';
                previewSpan.style.color = '#777';
            } else {
                const sampleData = selectedOption.getAttribute('data-sample-data');
                
                if (sampleData === '' || sampleData === null) {
                    previewSpan.textContent = '(Empty Value)';
                    previewSpan.style.fontStyle = 'italic';
                } else {
                    previewSpan.textContent = sampleData;
                }
            }
        };

        // If the column mappers exist (meaning PHP rendered the modal contents)
        if (mappers.length > 0) {
            
            // 1. Show the modal immediately since we have data to map
            showModal(); 
            
            // 2. Attach event listener and run initial update for all dropdowns
            mappers.forEach(mapper => {
                mapper.addEventListener('change', (event) => {
                    updatePreview(event.target);
                });
                
                // Run initial population on load
                updatePreview(mapper);
            });
            
        } else if (modal) {
             // Debugging check: If we have the modal element but no mappers, 
             // something is wrong with PHP data rendering, but we ensure 
             // the modal starts hidden, as intended by your CSS/HTML.
             hideModal();
        }
    });
})();