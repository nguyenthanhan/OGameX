<?php

namespace OGame\Jobs\Bot;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use OGame\Services\Bot\BotService;

class ProcessBotTurnsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Get bot log channel
     */
    protected function botLog()
    {
        return Log::channel('bot');
    }

    public function handle(BotService $botService): void
    {
        if (!config('ogame.bots.enabled', true)) {
            $this->botLog()->info('Bot system disabled, skipping processing');
            return;
        }

        $result = $botService->processAllBots();

        $this->botLog()->info('Bot processing completed', [
            'processed' => $result['processed'],
            'errors' => count($result['errors']),
            'stale_locks_cleared' => $result['stale_locks_cleared'],
        ]);
    }
}
