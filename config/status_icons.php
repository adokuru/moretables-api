<?php

/*
|--------------------------------------------------------------------------
| Front-of-House Status Icons
|--------------------------------------------------------------------------
|
| Single source of truth for how every reservation / service-stage / waitlist
| status is presented in the front-of-house apps: its display label, the hex
| colour shown on floor tables, timeline blocks and status tiles, and the
| icon key the app resolves to a local SVG component.
|
| Served verbatim by GET /api/v1/reference/status-icons. The `key` matches the
| app's status identifier; `icon` matches the SVG asset registered in the app.
| Edit colours/labels here without shipping a new app build.
|
*/

return [
    // Reservation lifecycle
    ['key' => 'confirmed',         'group' => 'reservation',     'label' => 'Confirmed',         'color' => '#1B5B07', 'icon' => 'confirmed'],
    ['key' => 'running_late',      'group' => 'reservation',     'label' => 'Running late',       'color' => '#FFC28D', 'icon' => 'running_late'],
    ['key' => 'partially_arrived', 'group' => 'reservation',     'label' => 'Partially Arrived', 'color' => '#CF55AE', 'icon' => 'partially_arrived'],
    ['key' => 'arrived',           'group' => 'reservation',     'label' => 'Arrived',           'color' => '#7880BD', 'icon' => 'arrived'],

    // Seated dining progression
    ['key' => 'seated',            'group' => 'seated',          'label' => 'Seated',            'color' => '#7880BD', 'icon' => 'seated'],
    ['key' => 'partially_seated',  'group' => 'seated',          'label' => 'Partially Seated',  'color' => '#39469C', 'icon' => 'partially_seated'],
    ['key' => 'appetizer',         'group' => 'seated',          'label' => 'Appetizer',         'color' => '#D4C5F6', 'icon' => 'appetizer'],
    ['key' => 'entree',            'group' => 'seated',          'label' => 'Entree',            'color' => '#506BDB', 'icon' => 'entree'],
    ['key' => 'dessert',           'group' => 'seated',          'label' => 'Dessert',           'color' => '#8854FF', 'icon' => 'dessert'],
    ['key' => 'cleared',           'group' => 'seated',          'label' => 'Cleared',           'color' => '#F66435', 'icon' => 'cleared'],
    ['key' => 'check_dropped',     'group' => 'seated',          'label' => 'Check Dropped',     'color' => '#27A001', 'icon' => 'check_dropped'],
    ['key' => 'paid',              'group' => 'seated',          'label' => 'Paid',              'color' => '#6DD989', 'icon' => 'paid'],

    // Finished
    ['key' => 'finished',          'group' => 'finished',        'label' => 'Finished',          'color' => '#707479', 'icon' => 'finished'],
    ['key' => 'bussing_needed',    'group' => 'finished',        'label' => 'Bussing Needed',    'color' => '#F7B20F', 'icon' => 'bussing_needed'],

    // Cancel / No-show
    ['key' => 'no_show',           'group' => 'cancel_no_show',  'label' => 'No-show',           'color' => '#657965', 'icon' => 'no_show'],
    ['key' => 'cancelled',         'group' => 'cancel_no_show',  'label' => 'Cancelled',         'color' => '#52261E', 'icon' => 'cancelled'],

    // Waitlist actions
    ['key' => 'notify',            'group' => 'waitlist',        'label' => 'Notify',            'color' => '#217606', 'icon' => 'notify'],
    ['key' => 'remove',            'group' => 'waitlist',        'label' => 'Remove',            'color' => '#A52700', 'icon' => 'remove'],
];
