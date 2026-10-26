<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Push\PushSender;
use Illuminate\Console\Command;

class SendPushNotifications extends Command
{
    protected $signature = 'push:send';

    protected $description = 'Send the push notifications whose moment has come';

    public function handle(PushSender $sender): void
    {
        $this->info($sender->sendDue().' push notifications sent');
    }
}
