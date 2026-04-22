<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Inventory Link
    |--------------------------------------------------------------------------
    |
    | When enabled, creating / transitioning a transport job will auto-upsert
    | and update the linked inventory row via JobObserver. While disabled (the
    | default), the inventory table exists but nothing writes to it from the
    | dispatch flow. This lets us deploy the schema change safely and flip
    | auto-linking on per environment when we're ready.
    |
    */

    'inventory_link' => env('INVENTORY_LINK_ENABLED', false),

];
