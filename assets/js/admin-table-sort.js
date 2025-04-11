jQuery(document).ready(function($) {
    console.log('WPSCD: admin-table-sort.js loaded.'); // Log 1: Script loaded

    // Only run on post/page list tables
    if (!$('body.wp-admin.edit-php').length) {
        return;
    }

    const table = $('table.wp-list-table');
    const theList = table.find('#the-list'); // tbody

    // Helper function to parse content from cells
    function getCellValue(row, cellIndex) {
        const cell = $(row).children('td, th').eq(cellIndex);
        let text = cell.text().trim();

        // Try to extract numbers, handling thousands separators and decimals
        // Remove percentage signs for CTR, ignore text after slash for combined col
        text = text.replace('%', '').split('/')[0].trim();
        // Try to remove thousand separators (might be locale specific, using common ones)
        text = text.replace(/[,.]/g, function(match) {
            // Replace comma with dot if it looks like a decimal separator precedes end
            // Replace dot with nothing if it looks like a thousand separator
            // This is imperfect but covers many common cases like 1,234.56 or 1.234,56
            // A more robust solution would need locale info.
            if (match === ',' && /^\d+,\d{1,2}$/.test(text)) {
                return '.';
            }
            if (match === '.' && /^\d+\.\d{3}/.test(text)) {
                return ''; // Remove thousand separator
            }
            return match; // Keep original if uncertain (e.g., single dot decimal)
        });

        const number = parseFloat(text);
        return isNaN(number) ? text.toLowerCase() : number; // Return number or lowercase text
    }

    // Target the link inside the sortable GSC header columns
    table.find('thead th.sortable[id^="gsc_"] a').on('click', function(e) {
        console.log('WPSCD: Click detected on a GSC column link.'); // Log: Click handler fired on link

        // Prevent WordPress default link navigation AND stop event propagation
        e.preventDefault();
        e.stopPropagation();

        const link = $(this);
        const th = link.closest('th'); // Get the parent th
        const columnIndex = th.index();
        const columnId = th.attr('id');

        console.log('WPSCD: Handling sort client-side for ' + columnId);

        let currentIsAsc = th.hasClass('asc');
        let direction = currentIsAsc ? 'desc' : 'asc';

        // Get all rows from the current view
        const rows = theList.find('tr').toArray();

        // Sort the rows
        rows.sort(function(a, b) {
            const valA = getCellValue(a, columnIndex);
            const valB = getCellValue(b, columnIndex);

            // Handle potential errors or non-comparable values gracefully
            const aIsNum = typeof valA === 'number';
            const bIsNum = typeof valB === 'number';

            if (aIsNum && bIsNum) {
                return direction === 'asc' ? valA - valB : valB - valA;
            } else if (aIsNum) {
                return direction === 'asc' ? -1 : 1; // Numbers first
            } else if (bIsNum) {
                return direction === 'asc' ? 1 : -1; // Numbers first
            } else {
                // Text comparison
                return direction === 'asc' ? valA.localeCompare(valB) : valB.localeCompare(valA);
            }
        });

        // Remove existing classes and add new ones
        table.find('thead th').removeClass('sorted asc desc');
        th.addClass('sorted').addClass(direction);

        // Re-append sorted rows
        theList.empty().append(rows);
    });

    // Optional: Handle clicks on the TH itself (outside the link) just in case,
    // perhaps by triggering a click on the inner link.
    table.find('thead th.sortable[id^="gsc_"]').on('click', function(e) {
        // If the click target wasn't the link itself or its children, trigger the link click
        if (!$(e.target).closest('a').length) {
            console.log('WPSCD: Click detected on TH padding, triggering link click.');
            $(this).find('a').trigger('click');
        }
    });
}); 