<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LoggingTest extends TestCase
{
    public function test_every_log_channel_the_code_writes_to_is_defined()
    {
        // An undefined channel falls back to the emergency logger, with a stack trace for every line.
        foreach (File::allFiles(app_path()) as $file) {
            preg_match_all("/Log::channel\('([^']+)'\)/", $file->getContents(), $matches);

            foreach ($matches[1] as $channel) {
                $this->assertNotNull(config("logging.channels.{$channel}"), "Undefined log channel [{$channel}] in {$file->getRelativePathname()}");
            }
        }
    }
}
