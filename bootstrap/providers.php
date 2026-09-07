<?php

use App\Providers\AppServiceProvider;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\ResidentService\ResidentServiceServiceProvider;
use App\Modules\Reception\ReceptionServiceProvider;

return [
    AppServiceProvider::class,
    BillingServiceProvider::class,
    ResidentServiceServiceProvider::class,
    ReceptionServiceProvider::class,
];
