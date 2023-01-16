<?php

namespace IgniterLabs\SquarePOS\Commands;

use IgniterLabs\SquarePOS\Models\SyncTask;
use Illuminate\Console\Command;

class CleanupCommand extends Command
{
    /**
     * The console command name.
     * @var string
     */
    protected $name = 'square:cleanup';

    /**
     * The console command description.
     * @var string
     */
    protected $description = 'Cleanup square sync tasks older than 30 days.';

    public static $logTTL = 365 / 4;

    /**
     * Execute the console command.
     * @return mixed
     */
    public function handle()
    {
        $this->comment('Cleaning old sync task...');
        $logTTL = now()->subDays(static::$logTTL)->format('Y-m-d H:i:s');

        $amountDeleted = SyncTask::where('created_at', '<', $logTTL)->delete();

        $this->info("Deleted {$amountDeleted} old sync tasks.");
        $this->comment('All done!');
    }
}
