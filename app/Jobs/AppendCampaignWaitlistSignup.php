<?php

namespace App\Jobs;

use App\Notifications\CampaignWaitlistConfirmationNotification;
use App\Services\GoogleSheetsWaitlistService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

class AppendCampaignWaitlistSignup implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(
        public string $email,
        public CarbonInterface $joinedAt,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(GoogleSheetsWaitlistService $googleSheets): void
    {
        $googleSheets->append($this->email, $this->joinedAt);

        Notification::route('mail', $this->email)
            ->notify(new CampaignWaitlistConfirmationNotification);
    }
}
