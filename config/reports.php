<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queued Excel Exports
    |--------------------------------------------------------------------------
    |
    | Report spreadsheets with more rows than this are generated on the queue
    | (Excel::queue) into the given disk, and the Relatórios screen polls for
    | the file; smaller ones are downloaded right away (US-11.2).
    |
    */

    'queue_after_rows' => (int) env('REPORTS_QUEUE_AFTER_ROWS', 5000),

    'exports_disk' => 'exports',

];
