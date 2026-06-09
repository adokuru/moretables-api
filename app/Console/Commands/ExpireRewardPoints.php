<?php

namespace App\Console\Commands;

use App\Services\RewardProgramService;
use Illuminate\Console\Command;

class ExpireRewardPoints extends Command
{
    protected $signature = 'app:expire-reward-points';

    protected $description = 'Expire reward point lots that are older than one year';

    public function handle(RewardProgramService $rewardProgramService): int
    {
        $expiredLots = $rewardProgramService->expireDuePointLots();

        $this->info("Expired {$expiredLots} reward point lot(s).");

        return self::SUCCESS;
    }
}
