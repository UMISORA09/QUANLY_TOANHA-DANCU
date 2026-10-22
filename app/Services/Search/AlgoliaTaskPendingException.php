<?php

namespace App\Services\Search;

use RuntimeException;

class AlgoliaTaskPendingException extends RuntimeException
{
    public function __construct(public int $taskId)
    {
        parent::__construct('Algolia task '.$taskId.' is not published yet. Retry synchronization.');
    }
}
