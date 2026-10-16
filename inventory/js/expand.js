// js/expand.js - Debug Version (with added logging)
document.addEventListener('DOMContentLoaded', function() {
    
    // 1. Try selecting by the specific class name (Primary attempt)
    var buttons = document.querySelectorAll('.toggle-details-btn');

    // 2. Fallback: Try selecting by a common attribute if the class name is wrong (Backup attempt)
    if (buttons.length === 0) {
        buttons = document.querySelectorAll('[data-bs-toggle="collapse"][data-pallet-id]');
        console.log("Primary selector failed. Trying fallback selector.");
    }
    
    // Report findings to the console
    console.log("Total toggle buttons found:", buttons.length); 

    if (buttons.length === 0) {
        console.error("CRITICAL: No pallet content toggle buttons found. Check the class name in pallete.php.");
        return; // Stop if no buttons are found
    }

    // Attach listener to all found buttons
    buttons.forEach(function(toggleBtn) {
        
        toggleBtn.addEventListener('click', function() {
            
            // Console log should now run when you click a button
            console.log("Toggle button clicked. Pallet ID check underway.");
            
            // 1. Get the Pallet ID and the target collapse ID from the button
            var palletId = toggleBtn.getAttribute('data-pallet-id');
            var collapseId = toggleBtn.getAttribute('data-bs-target'); 

            // --- NEW DEBUG LOGS START ---
            console.log("Gathered Data: Pallet ID =", palletId);
            console.log("Gathered Data: Collapse Target ID =", collapseId);
            // --- NEW DEBUG LOGS END ---

            // 2. Find the content container
            var collapseElement = document.querySelector(collapseId);
            var contentDiv = collapseElement ? collapseElement.querySelector('.detail-content') : null;

            if (!palletId || !contentDiv) {
                console.error("Pallet ID or content div missing. ID:", palletId);
                return;
            }

            // 3. Check if content has already been loaded for this pallet
            var isLoaded = contentDiv.getAttribute('data-loaded');
            if (isLoaded === 'true') {
                return;
            }
            
            // 4. Construct the fetch URL
            // Ensure this path matches where your get_pallet_contents.php is relative to pallete.php
            var fetchUrl = `/dev/utils/get_pallet_contents.php?id=${palletId}`;      

            // --- NEW DEBUG LOG ---
            console.log("Gathered Data: Fetch URL =", fetchUrl);
            // --- END DEBUG LOG ---

            contentDiv.innerHTML = '<p class="text-center text-muted m-0">Loading contents...</p>';

            // 5. Make the AJAX call
            fetch(fetchUrl)
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    return response.text();
                })
                .then(function(html) {
                    contentDiv.innerHTML = html; 
                    contentDiv.setAttribute('data-loaded', 'true'); 
                })
                .catch(function(error) {
                    console.error('Fetch error:', error);
                    contentDiv.innerHTML = '<p class="text-danger m-0">Error fetching pallet contents.</p>';
                });
        });
    });
});