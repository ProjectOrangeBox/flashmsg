<?php

return [
    // semantic types that require manual dismissal in the JS layer
    'sticky types' => ['danger', 'warning'],
    // legacy color names resolve to their semantic types before storage
    'type aliases' => [
        'red' => 'danger',
        'yellow' => 'warning',
        'blue' => 'info',
        'green' => 'success',
    ],
    // seconds before the first auto-dismiss, ms added per queued message
    'initial pause' => 3,
    'pause for each' => 1000,
    'default type' => 'info',
    // fallback when the input service carries no HTTP referer
    'http referer' => '',
    // data-service key the queue mirrors into for views - deliberately
    // namespaced so it can't collide with a page's own view variables
    'view variable' => 'flash_messages_array',
    'session msg key' => '__#internal::flash::msg#__',
];
