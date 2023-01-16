<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use IgniterLabs\SquarePOS\Classes\SquareClient;
use IgniterLabs\SquarePOS\Models\SyncTask;

abstract class AbstractSyncPipe
{
    /**
     * @var \IgniterLabs\SquarePOS\Classes\SquareClient
     */
    protected $square;

    /**
     * @var \IgniterLabs\SquarePOS\Models\SyncTask
     */
    protected SyncTask $syncTask;

    protected bool $hasCursor = false;

    public function __construct(SyncTask $syncTask)
    {
        $this->syncTask = $syncTask;

        $this->square = resolve(SquareClient::class);
    }

    abstract public function execute();

    public function onComplete()
    {
    }

    protected function handleResponseCursor($key, ?string $cursor): void
    {
        if (strlen($cursor ?? '')) {
            $this->hasCursor = true;
            $this->syncTask->setConfigData($key, $cursor)->save();

            $this->execute();
        }
    }
}
