<?php

return [
    'attachment_disk' => env('ANNOUNCEMENT_ATTACHMENT_DISK', env('TICKET_ATTACHMENT_DISK', env('APP_ENV') === 'production' ? 's3' : 'local')),
];
