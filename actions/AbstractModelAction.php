<?php

namespace IgniterLabs\SquarePOS\Actions;

use IgniterLabs\SquarePOS\Classes\SquareClient;
use IgniterLabs\SquarePOS\Models\SyncTask;
use System\Actions\ModelAction;

class AbstractModelAction extends ModelAction
{
    /**
     * @var \IgniterLabs\SquarePOS\Models\SyncTask
     */
    protected $syncTask;

    /**
     * @var \IgniterLabs\SquarePOS\Classes\SquareClient
     */
    protected $square;

    public function __construct($model)
    {
        parent::__construct($model);

        $this->square = resolve(SquareClient::class);
    }

    public function squareSetSyncTask(SyncTask $syncTask)
    {
        $this->syncTask = $syncTask;

        return $this;
    }

    public function getSquareId()
    {
        return $this->model->square_id ?: '#temp_'.$this->model->getMorphClass().'_'.$this->model->getKey();
    }
}
