<?php
/**
 * Public Display Settings
 * Configure what data is visible on the public website
 */

return [
    // Show all riders publicly or only those with results
    // Options: 'all' or 'with_results'
    'public_riders_display' => 'with_results',

    // Minimum number of results required to show rider (when 'with_results' is selected)
    'min_results_to_show' => 1,
];
