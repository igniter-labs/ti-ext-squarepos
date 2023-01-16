<?php

namespace IgniterLabs\SquarePOS\Commands;

use Igniter\Flame\Support\Str;
use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Models\SyncTask;
use Illuminate\Console\Command;

class SyncCommand extends Command
{
    /**
     * The console command name.
     * @var string
     */
    protected $name = 'square:sync';

    /**
     * The console command description.
     * @var string
     */
    protected $description = 'Sync items between TI and square.';

    /**
     * Execute the console command.
     * @return mixed
     */
    public function handle()
    {
        $lastUuid = Settings::get('sync_inventory_uuid');

        $syncTask = SyncTask::startSync(Str::uuid(), Settings::getSystemOfRecord());

        if ($lastUuid && ($lastSyncTask = SyncTask::findBy($lastUuid))) {
            $syncTask->config_data = $lastSyncTask->config_data;
            $syncTask->save();
        }

        $syncTask->dispatchSync();

        Settings::set('sync_inventory_uuid', $syncTask->uuid);

        $this->output->writeln('Sync complete');
    }
}
