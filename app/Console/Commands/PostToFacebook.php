<?php

namespace App\Console\Commands;

use App\Social\SocialPoster;
use Illuminate\Console\Command;

/**
 * Publishes the next post of the "how Zonseob works" series to the Facebook Page when a posting
 * time is due. Scheduled every fifteen minutes; does nothing until the Page is connected and
 * posting is switched on from the Facebook posts admin page.
 */
class PostToFacebook extends Command
{
    protected $signature = 'zonseo:post-to-facebook {--force : Publish the next post now, whatever the posting times say}';

    protected $description = 'Publish the next post in the Facebook series when a posting time is due';

    public function handle(SocialPoster $poster): int
    {
        if (! $poster->isConfigured()) {
            $this->info('Facebook is not connected; nothing to do.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        if (! $force && ! $poster->isEnabled()) {
            $this->info('Facebook posting is switched off.');

            return self::SUCCESS;
        }

        $slot = $poster->dueSlot();
        if (! $force && ! $slot) {
            $this->info('No posting time is due.');

            return self::SUCCESS;
        }

        $post = $poster->postNext($slot);
        if (! $post) {
            $this->info('The series is complete: every post has been published.');

            return self::SUCCESS;
        }

        if ($post->isPosted()) {
            $this->info("Posted [{$post->kind}:{$post->key}] {$post->title} → {$post->permalink()}");

            return self::SUCCESS;
        }

        $this->error("Failed [{$post->kind}:{$post->key}] {$post->title}: {$post->error}");

        return self::FAILURE;
    }
}
