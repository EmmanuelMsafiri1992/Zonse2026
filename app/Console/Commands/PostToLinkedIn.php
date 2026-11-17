<?php

namespace App\Console\Commands;

use App\Social\LinkedInPoster;
use App\Social\SocialException;
use Illuminate\Console\Command;

/**
 * Publishes the next post of the series to the LinkedIn Page when a posting time is due.
 * Does nothing until a token is saved and posting is switched on from the LinkedIn posts page.
 */
class PostToLinkedIn extends Command
{
    protected $signature = 'zonseo:post-to-linkedin
        {--force : Publish the next post now, whatever the posting times say}
        {--check : Only check the saved token can post as the Page}';

    protected $description = 'Publish the next post in the series to the LinkedIn Page when a posting time is due';

    public function handle(LinkedInPoster $poster): int
    {
        if ($this->option('check')) {
            try {
                $this->info('Token OK for '.$poster->page()->name().', runs out about '.$poster->settings()['token_expires_at'].'.');

                return self::SUCCESS;
            } catch (SocialException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        if (! $poster->isConfigured()) {
            $this->info('LinkedIn is not connected; nothing to do.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        if (! $force && ! $poster->isEnabled()) {
            $this->info('LinkedIn posting is switched off.');

            return self::SUCCESS;
        }

        $slot = $poster->dueSlot();
        if (! $force && ! $slot) {
            $this->info('No posting time is due.');

            return self::SUCCESS;
        }

        $post = $poster->postNext($slot);
        if (! $post) {
            $this->info('The series is complete on LinkedIn.');

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
