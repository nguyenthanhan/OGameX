<?php

namespace OGame\Console\Commands\Bot;

use Illuminate\Console\Command;
use OGame\Services\Bot\BotService;

class ProcessBotsNow extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bot:run {bot? : Specific bot ID to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process bot turns immediately (for testing)';

    /**
     * Execute the console command.
     */
    public function handle(BotService $botService): int
    {
        $botId = $this->argument('bot');

        if ($botId) {
            // Process specific bot
            $this->info("🤖 Processing Bot {$botId}...\n");

            try {
                $botService->processBotTurn((int) $botId);
                $this->info("✅ Bot {$botId} processed successfully!");

                // Show log file location
                $date = date('Y-m-d');
                $logFile = storage_path("logs/bots/bot-{$botId}/{$date}.log");
                $this->line("\n💡 View log:");
                $this->line("  cat {$logFile}");
            } catch (\Exception $e) {
                $this->error("❌ Failed: " . $e->getMessage());
                return 1;
            }
        } else {
            // Process all bots
            $this->info("🤖 Processing all enabled bots...\n");

            $result = $botService->processAllBots();

            $this->info("✅ Processing completed!");
            $this->line("  Processed: {$result['processed']}");
            $this->line("  Errors: " . count($result['errors']));
            $this->line("  Stale locks cleared: {$result['stale_locks_cleared']}");

            if (!empty($result['errors'])) {
                $this->line("\n⚠️  Errors:");
                foreach ($result['errors'] as $error) {
                    $this->line("  Bot {$error['bot_id']}: {$error['error']}");
                }
            }

            if ($result['processed'] > 0) {
                $date = date('Y-m-d');
                $this->line("\n💡 View logs:");
                $this->line("  ls -la storage/logs/bots/");
                $this->line("  cat storage/logs/bots/bot-*/{$date}.log");
            }
        }

        return 0;
    }
}
