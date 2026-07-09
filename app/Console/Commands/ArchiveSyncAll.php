<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\SyncArtistsJob;

class ArchiveSyncAll extends Command
{
    protected $signature = 'archives:sync-all';
    protected $description = 'Sync all archive records: query aggregator for artists, search Alma/Primo, store results';

    public function handle(): void
    {
        $this->info('Dispatching SyncArtistsJob...');
        SyncArtistsJob::dispatch();
        $this->info('Dispatched.');
    }
}
