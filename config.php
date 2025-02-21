<?php
// Configurations
define('AUTH_TOKEN', "d7db72b0f9f083fe3c33441412a47e1e");
define('TIMESTAMP', date('Y-m-d H:i:s', strtotime('-5 minutes'))); // Production
// define('TIMESTAMP', "2025-02-21 00:00:00"); // Testing
define('LEAD_FILE', __DIR__ . '/processed_leads.txt');
define('BAYUT_SOURCE_ID', 'UC_LTULS9');
define('DUBIZZLE_SOURCE_ID', 'UC_B35FBN');
define('DEFAULT_ASSIGNED_USER_ID', '1893'); // Justine Panganiban
define('SECONDARY_PIPELINE_ID', 24);