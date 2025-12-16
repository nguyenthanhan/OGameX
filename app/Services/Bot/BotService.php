<?php

namespace OGame\Services\Bot;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\User;
use OGame\Services\PlayerService;

/**
 * Bot Service - Manages bot lifecycle and processing
 */
class BotService
{
    public function __construct(
        protected BotQuotaService $quotaService,
        protected BotGameStateCollector $stateCollector,
        protected BotAIService $aiService,
        protected BotActionExecutor $actionExecutor,
        protected PlanetServiceFactory $planetFactory
    ) {
    }

    /**
     * Get bot log channel
     */
    protected function botLog()
    {
        return Log::channel('bot');
    }

    public function createBot(string $username, int $skillLevel, string $strategy, ?int $botAiConfigId = null): User
    {
        if ($skillLevel < 1 || $skillLevel > 10) {
            throw new \InvalidArgumentException('Skill level must be between 1 and 10');
        }

        if (!in_array($strategy, ['miner', 'raider', 'defender', 'balanced'])) {
            throw new \InvalidArgumentException('Invalid strategy');
        }

        $bot = User::create([
            'username' => $username,
            'email' => $username . '@bot.local',
            'password' => bcrypt(Str::random(32)),
            'lang' => 'en',
            'is_bot' => true,
            'bot_enabled' => true,
            'bot_skill_level' => $skillLevel,
            'bot_strategy' => $strategy,
            'bot_ai_config_id' => $botAiConfigId,
            'bot_last_heartbeat' => now(),
            'time' => (string)time(), // Set initial activity time as Unix timestamp
            'register_time' => (string)time(),
        ]);

        // Create starting planet for the bot
        try {
            $playerService = resolve(PlayerService::class, ['player_id' => $bot->id]);
            $homePlanet = $this->planetFactory->createInitialPlanetForPlayer($playerService, 'Homeworld');
            $this->botLog()->info("Bot {$bot->id} ({$username}) created with planet {$homePlanet->getPlanetId()}");
        } catch (Exception $e) {
            $this->botLog()->error("Failed to create planet for bot {$bot->id}: " . $e->getMessage());
            // Don't fail bot creation, planet can be created manually
        }

        $this->botLog()->info("Bot created: {$username}, strategy: {$strategy}, skill: {$skillLevel}");
        return $bot;
    }

    public function processAllBots(): array
    {
        $processed = 0;
        $errors = [];
        $staleLocksCleared = 0;

        // Clear orphaned locks (locks older than 5 minutes without heartbeat update)
        $staleBots = DB::table('users')
            ->where('is_bot', true)
            ->whereNotNull('bot_processing_until')
            ->where('bot_processing_until', '>', now())
            ->where(function ($q) {
                $q->whereNull('bot_last_heartbeat')
                    ->orWhere('bot_last_heartbeat', '<', now()->subMinutes(5));
            })
            ->get();

        foreach ($staleBots as $staleBot) {
            $this->botLog()->alert("Orphaned lock detected on bot {$staleBot->id}, clearing");
            DB::table('users')->where('id', $staleBot->id)->update(['bot_processing_until' => null]);
            $staleLocksCleared++;
        }

        // Get bots to process
        $actionInterval = (int)config('ogame.bots.action_interval_minutes', 5);
        $lockDuration = (int)config('ogame.bots.lock_duration_seconds', 60);

        $cutoffTime = now()->subMinutes($actionInterval);
        $lockUntil = now()->addSeconds($lockDuration);

        $bots = DB::table('users')
            ->where('is_bot', true)
            ->where('bot_enabled', true)
            ->where(fn ($q) => $q->whereNull('bot_last_action')->orWhere('bot_last_action', '<=', $cutoffTime))
            ->where(fn ($q) => $q->whereNull('bot_processing_until')->orWhere('bot_processing_until', '<=', now()))
            ->get();

        if ($bots->isEmpty()) {
            return ['processed' => 0, 'errors' => [], 'stale_locks_cleared' => $staleLocksCleared];
        }

        $botIds = $bots->pluck('id')->toArray();
        DB::table('users')->whereIn('id', $botIds)->update(['bot_processing_until' => $lockUntil]);

        foreach ($botIds as $botId) {
            try {
                $this->processBotTurn($botId);
                $processed++;
            } catch (Exception $e) {
                // Log to Laravel log
                $this->botLog()->error("Bot {$botId} failed: " . $e->getMessage(), [
                    'exception' => get_class($e),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Also log to bot file for visibility
                $this->logToFile(
                    $botId,
                    "❌ TURN FAILED (caught in processAllBots)",
                    "Error: " . $e->getMessage() . "\n" .
                    "Exception: " . get_class($e) . "\n" .
                    "Trace: " . substr($e->getTraceAsString(), 0, 500)
                );

                $errors[] = ['bot_id' => $botId, 'error' => $e->getMessage()];
            }
        }

        DB::table('users')->whereIn('id', $botIds)->update([
            'bot_last_action' => now(),
            'bot_processing_until' => null,
            'time' => (string)time(), // Update last activity time as Unix timestamp to prevent showing as inactive
        ]);

        return ['processed' => $processed, 'errors' => $errors, 'stale_locks_cleared' => $staleLocksCleared];
    }

    public function processBotTurn(int $botUserId): void
    {
        $bot = User::findOrFail($botUserId);
        if (!$bot->is_bot || !$bot->bot_enabled) {
            throw new Exception("User {$botUserId} is not an enabled bot");
        }

        $bot->update(['bot_last_heartbeat' => now()]);
        $turnId = Str::uuid();

        // Log turn start
        $this->logToFile($bot->id, "🎮 TURN STARTED", "Turn ID: {$turnId}", $turnId);

        try {
            // Collect game state
            $gameState = $this->stateCollector->collectGameState($bot);

            // Get last turn summary
            $lastTurnSummary = $this->getLastTurnSummary($bot);

            // Get AI decision (or basic miner mode)
            $decision = $this->aiService->getDecision($bot, $gameState, $lastTurnSummary);

            // Log decision with details
            $strategy = $decision['overall_strategy'] ?? 'No strategy provided';
            $actionCount = count($decision['actions']);
            $actionTypes = array_column($decision['actions'], 'action_type');
            $tokensUsed = $decision['_metadata']['tokens_used'] ?? null;
            $costUsd = $decision['_metadata']['cost'] ?? null;
            $this->botLog()->info("[TURN_DECISION] Bot:{$bot->id} Turn:{$turnId} Actions:{$actionCount} Types:" . implode(',', $actionTypes) . " Tokens:{$tokensUsed} Cost:$" . ($costUsd ?? 0) . " | Bot {$bot->id} turn {$turnId}: {$strategy}", [
                'actions' => $actionCount,
                'action_types' => $actionTypes,
                'tokens_used' => $tokensUsed,
                'cost_usd' => $costUsd,
            ]);

            // Log decisions to bot_decisions_active (before execution)
            foreach ($decision['actions'] as $index => $action) {
                $idempotencyKey = "{$turnId}:{$index}";

                DB::table('bot_decisions_active')->insert([
                    'user_id' => $bot->id,
                    'turn_id' => $turnId,
                    'idempotency_key' => $idempotencyKey,
                    'action_hash' => hash('sha256', json_encode($action)),
                    'action_type' => $action['action_type'],
                    'planet_id' => $action['planet_id'] ?? null,
                    'target' => $action['target'] ?? null,
                    'quantity' => $action['quantity'] ?? null,
                    'overall_strategy' => $decision['overall_strategy'] ?? 'No strategy provided',
                    'result' => 'pending', // Will be updated after execution
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Execute actions via BotActionExecutor
            $executionResults = $this->actionExecutor->executeDecisions($bot, $decision, $turnId);

            $successCount = collect($executionResults)->where('result.success', true)->count();
            $failedCount = count($executionResults) - $successCount;

            // Log execution results
            $this->logToFile(
                $bot->id,
                "📊 EXECUTION RESULTS",
                "Success: {$successCount}, Failed: {$failedCount}\n" .
                "Actions:\n" . $this->formatExecutionResults($executionResults),
                $turnId
            );

            $bot->update(['bot_last_heartbeat' => now()]);

            $this->logToFile($bot->id, "✅ TURN COMPLETED", "Turn ID: {$turnId}", $turnId);

            $this->botLog()->info("[TURN_COMPLETE] Bot:{$bot->id} Turn:{$turnId} Success:{$successCount} Failed:{$failedCount} | Bot {$bot->id} turn {$turnId} completed: {$successCount} success, {$failedCount} failed");
        } catch (Exception $e) {
            $this->logToFile(
                $bot->id,
                "❌ TURN FAILED",
                "Turn ID: {$turnId}\n" .
                "Error: " . $e->getMessage(),
                $turnId
            );
            $errorMsg = str_replace([' ', "\n"], ['_', ''], substr($e->getMessage(), 0, 50));
            $this->botLog()->error("[TURN_FAILED] Bot:{$bot->id} Turn:{$turnId} Error:{$errorMsg} | Bot {$bot->id} turn {$turnId} failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Write to bot's daily log file
     */
    private function logToFile(int $botId, string $title, string $content, ?string $turnId = null): void
    {
        try {
            $date = date('Y-m-d');
            $timestamp = date('Y-m-d H:i:s');
            $logDir = storage_path("logs/bots/bot-{$botId}");

            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }

            $logFile = "{$logDir}/{$date}.log";

            // Map title to searchable tag
            $tagMap = [
                '🎮 TURN STARTED' => 'TURN_START',
                '✅ TURN COMPLETED' => 'TURN_COMPLETE',
                '❌ TURN FAILED' => 'TURN_FAILED',
                '📊 EXECUTION RESULTS' => 'EXECUTION_RESULTS',
            ];
            $tag = $tagMap[$title] ?? 'LOG';

            // Build searchable header line
            $searchableLine = "[{$tag}] [{$timestamp}] Bot:{$botId}";
            if ($turnId) {
                $searchableLine .= " Turn:{$turnId}";
            }

            $logContent = str_repeat('=', 100) . "\n";
            $logContent .= "{$searchableLine}\n";
            $logContent .= str_repeat('=', 100) . "\n";
            $logContent .= "{$title} [{$timestamp}]\n";
            $logContent .= str_repeat('=', 100) . "\n";
            $logContent .= $content . "\n\n";

            file_put_contents($logFile, $logContent, FILE_APPEND);
        } catch (\Exception $e) {
            // Don't fail if logging fails
        }
    }

    /**
     * Format execution results for logging
     */
    private function formatExecutionResults(array $results): string
    {
        $output = '';
        foreach ($results as $result) {
            $status = $result['result']['success'] ? '✅' : '❌';
            $action = $result['action'];
            $actionType = $action['action_type'] ?? 'UNKNOWN';
            $target = $action['target'] ?? 'N/A';
            $planetId = $action['planet_id'] ?? null;

            // Add searchable tag line
            $tag = $result['result']['success'] ? 'ACTION_SUCCESS' : 'ACTION_FAILED';
            $searchableLine = "[{$tag}] Type:{$actionType} Target:{$target}";
            if ($planetId) {
                $searchableLine .= " Planet:{$planetId}";
            }
            if (!$result['result']['success']) {
                $error = $result['result']['error'] ?? 'Unknown error';
                $searchableLine .= " Error:" . str_replace([' ', "\n"], ['_', ''], substr($error, 0, 50));
            }

            $output .= "{$searchableLine}\n";
            $output .= "  {$status} {$actionType} → {$target}";

            if ($planetId) {
                $output .= " (Planet: {$planetId})";
            }

            if (!$result['result']['success']) {
                $output .= " (Error: {$result['result']['error']})";
            }

            $output .= "\n";
        }
        return $output;
    }

    /**
     * Get last turn summary for a bot
     *
     * @param User $bot
     * @return array
     */
    protected function getLastTurnSummary(User $bot): array
    {
        $decisions = DB::table('bot_decisions_active')
            ->where('user_id', $bot->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        if ($decisions->isEmpty()) {
            return [];
        }

        $lastTurnId = $decisions->first()->turn_id;
        $lastTurnActions = $decisions->where('turn_id', $lastTurnId);

        return [
            'turn_id' => $lastTurnId,
            'actions' => $lastTurnActions->map(fn ($d) => [
                'action_type' => $d->action_type,
                'target' => $d->target,
                'result' => $d->result,
            ])->toArray(),
            'overall_strategy' => $decisions->first()->overall_strategy ?? 'No strategy provided',
        ];
    }

    /**
     * Safely delete a bot and all related data
     *
     * Handles cascade deletion of all related records to avoid foreign key constraint violations.
     * Checks for active fleet missions and provides options to handle them.
     *
     * Usage:
     * docker compose exec ogamex-app php artisan tinker
     * $botService = app(\OGame\Services\Bot\BotService::class);
     * $botService->deleteBot(6);  // Will throw exception if fleets are flying
     * $botService->deleteBot(6, true);  // Force delete (cancels all fleets)
     *
     * @param int $botId
     * @param bool $force If true, will cancel all active fleet missions before deleting
     * @return bool
     * @throws Exception
     */
    public function deleteBot(int $botId, bool $force = false): bool
    {
        $bot = User::find($botId);

        if (!$bot || !$bot->is_bot) {
            throw new Exception("User {$botId} is not a bot or does not exist");
        }

        // Check for active fleet missions
        // Get fleets sent by the bot
        $activeFleets = DB::table('fleet_missions')
            ->where('user_id', $botId)
            ->where('processed', 0)
            ->where('canceled', 0)
            ->get();

        // Also check for incoming fleets to bot's planets
        $botPlanetIds = DB::table('planets')
            ->where('user_id', $botId)
            ->pluck('id')
            ->toArray();

        if (!empty($botPlanetIds)) {
            $incomingFleets = DB::table('fleet_missions')
                ->whereIn('planet_id_to', $botPlanetIds)
                ->where('user_id', '!=', $botId) // Exclude bot's own fleets
                ->where('processed', 0)
                ->where('canceled', 0)
                ->get();

            $activeFleets = $activeFleets->merge($incomingFleets);
        }

        if ($activeFleets->isNotEmpty() && !$force) {
            $fleetCount = $activeFleets->count();
            throw new Exception(
                "Cannot delete bot: {$fleetCount} active fleet mission(s) in progress. " .
                "Wait for fleets to return or use deleteBot({$botId}, true) to force delete and cancel all fleets."
            );
        }

        DB::beginTransaction();

        try {
            $player = new PlayerService($botId);

            // If force delete, cancel all active fleet missions first
            if ($force && $activeFleets->isNotEmpty()) {
                $this->botLog()->warning("Force deleting bot {$botId}: Canceling {$activeFleets->count()} active fleet missions");

                foreach ($activeFleets as $fleet) {
                    // Mark fleet as canceled
                    DB::table('fleet_missions')
                        ->where('id', $fleet->id)
                        ->update([
                            'canceled' => 1,
                            'processed' => 1,
                            'updated_at' => now(),
                        ]);
                }
            }

            // Delete all planet-related data first (to avoid foreign key constraints)
            foreach ($player->planets as $planet) {
                $planetId = $planet->getPlanetId();

                // Delete building queues
                DB::table('building_queues')->where('planet_id', $planetId)->delete();

                // Delete research queues
                DB::table('research_queues')->where('planet_id', $planetId)->delete();

                // Delete unit queues
                DB::table('unit_queues')->where('planet_id', $planetId)->delete();

                // Delete fleet missions (from and to this planet)
                DB::table('fleet_missions')->where('planet_id_from', $planetId)->delete();
                DB::table('fleet_missions')->where('planet_id_to', $planetId)->delete();

                // Delete espionage reports
                DB::table('espionage_reports')->where('planet_user_id', $botId)->delete();
                DB::table('espionage_reports')->where('enemy_user_id', $botId)->delete();

                // Delete battle reports
                DB::table('battle_reports')->where('attacker_user_id', $botId)->delete();
                DB::table('battle_reports')->where('defender_user_id', $botId)->delete();

                // Delete debris fields
                DB::table('debris_fields')->where('planet_galaxy', $planet->getPlanetCoordinates()->galaxy)
                    ->where('planet_system', $planet->getPlanetCoordinates()->system)
                    ->where('planet_position', $planet->getPlanetCoordinates()->position)
                    ->delete();

                // Delete notes
                DB::table('notes')->where('user_id', $botId)->delete();

                // Delete messages
                DB::table('messages')->where('user_id_to', $botId)->delete();
                DB::table('messages')->where('user_id_from', $botId)->delete();
            }

            // Delete planets (now safe after deleting all references)
            DB::table('planets')->where('user_id', $botId)->delete();

            // Delete bot-specific data
            DB::table('bot_decisions_active')->where('user_id', $botId)->delete();
            DB::table('bot_quota_usage')->where('bot_id', $botId)->delete();

            // Delete user tech
            DB::table('users_tech')->where('user_id', $botId)->delete();

            // Delete user roles and permissions
            DB::table('model_has_roles')->where('model_id', $botId)->where('model_type', User::class)->delete();
            DB::table('model_has_permissions')->where('model_id', $botId)->where('model_type', User::class)->delete();

            // Finally, delete the user
            $bot->delete();

            DB::commit();

            $this->botLog()->info("Bot {$botId} ({$bot->username}) successfully deleted");

            return true;
        } catch (Exception $e) {
            DB::rollBack();
            $this->botLog()->error("Failed to delete bot {$botId}: " . $e->getMessage());
            throw new Exception("Failed to delete bot: " . $e->getMessage());
        }
    }

    /**
     * Check if a bot has active fleet missions
     *
     * @param int $botId
     * @return array ['has_fleets' => bool, 'count' => int, 'missions' => array]
     */
    public function checkActiveFleets(int $botId): array
    {
        // Get fleets sent by the bot
        $activeFleets = DB::table('fleet_missions')
            ->where('user_id', $botId)
            ->where('processed', 0)
            ->where('canceled', 0)
            ->get();

        // Also check for incoming fleets to bot's planets
        $botPlanetIds = DB::table('planets')
            ->where('user_id', $botId)
            ->pluck('id')
            ->toArray();

        if (!empty($botPlanetIds)) {
            $incomingFleets = DB::table('fleet_missions')
                ->whereIn('planet_id_to', $botPlanetIds)
                ->where('user_id', '!=', $botId) // Exclude bot's own fleets
                ->where('processed', 0)
                ->where('canceled', 0)
                ->get();

            $activeFleets = $activeFleets->merge($incomingFleets);
        }

        $missions = [];
        foreach ($activeFleets as $fleet) {
            $missions[] = [
                'id' => $fleet->id,
                'mission_type' => $fleet->mission_type ?? 'unknown',
                'from_planet' => $fleet->planet_id_from,
                'to_planet' => $fleet->planet_id_to,
                'time_arrival' => $fleet->time_arrival ?? null,
                'time_return' => $fleet->time_return ?? null,
            ];
        }

        return [
            'has_fleets' => $activeFleets->isNotEmpty(),
            'count' => $activeFleets->count(),
            'missions' => $missions,
        ];
    }
}
