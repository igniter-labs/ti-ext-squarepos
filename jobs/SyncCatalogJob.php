<?php

namespace IgniterLabs\SquarePOS\Jobs;

use Exception;
use IgniterLabs\SquarePOS\Exceptions\SyncException;
use IgniterLabs\SquarePOS\Models\SyncTask;
use IgniterLabs\SquarePOS\SyncPipes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncCatalogJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $uuid = '';

    public function handle()
    {
        if (!$this->uuid) {
            throw new SyncException('Missing sync task identifier');
        }

        $syncTask = SyncTask::findBy($this->uuid);

        $syncTask->setSyncJob($this);

        $this->getSyncPipes($syncTask)->each(function (string $syncPipe) use ($syncTask) {
            try {
                (new $syncPipe($syncTask))->execute();
            } catch (Throwable | Exception $ex) {
                $syncTask->noteException($ex);
            }

            $syncTask->save();
        });

        $syncTask->markAsComplete();
    }

    public function uuid(string $uuid): self
    {
        $this->uuid = $uuid;

        return $this;
    }

    protected function getSyncPipes($syncTask)
    {
        if ($syncTask->isSystemOfRecordSquare()) {
            $pipes[] = SyncPipes\PullCategories::class;
            $pipes[] = SyncPipes\PullItemOptions::class;
            $pipes[] = SyncPipes\PullItems::class;
            $pipes[] = SyncPipes\PullStocks::class;
        }
        else {
            $pipes[] = SyncPipes\PushCategories::class;
            $pipes[] = SyncPipes\PushItemOptions::class;
            $pipes[] = SyncPipes\PushItems::class;
            $pipes[] = SyncPipes\PushStocks::class;
        }

        return collect($pipes);
    }
}
