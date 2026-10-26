<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    protected $signature = 'push:vapid';

    protected $description = 'Generate the key pair the push services know the app by, for the .env';

    public function handle(): int
    {
        if (config('push.vapid.public_key') && ! $this->confirm('There are keys already: with new ones, every device has to turn push notifications on again. Generate new ones anyway?')) {
            return self::FAILURE;
        }

        $keys = VAPID::createVapidKeys();

        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);

        return self::SUCCESS;
    }
}
