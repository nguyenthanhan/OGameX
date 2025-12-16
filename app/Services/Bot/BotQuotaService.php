<?php

namespace OGame\Services\Bot;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use OGame\Exceptions\QuotaExceededException;
use OGame\Models\User;

/**
 * Bot Quota Service
 *
 * Manages atomic quota checking and enforcement to prevent race conditions.
 * Uses database transactions for consistency instead of cache.
 */
class BotQuotaService
{
    /**
     * Atomically check and record quota usage
     *
     * CRITICAL: Uses database transaction with row locking to prevent race conditions
     * Only tracks requests per hour
     *
     * @param User $bot
     * @return array
     * @throws QuotaExceededException
     */
    public function atomicCheckAndRecord(User $bot): array
    {
        return DB::transaction(function () use ($bot) {
            $currentHour = Carbon::now()->startOfHour();

            // Get per-bot request limit from config
            $perBotHourlyRequests = config('ogame.bots.quotas.requests_per_bot_hourly', 12);

            // Check per-bot hourly requests
            $botHourlyUsage = DB::table('bot_quota_usage')
                ->where('bot_id', $bot->id)
                ->where('hour', $currentHour)
                ->lockForUpdate()
                ->first();

            $botRequestsUsed = $botHourlyUsage ? $botHourlyUsage->requests_used : 0;
            if ($botRequestsUsed + 1 > $perBotHourlyRequests) {
                throw new QuotaExceededException('requests', "Bot {$bot->id} exceeded hourly request quota ({$perBotHourlyRequests} requests/hour)");
            }

            // Record usage atomically
            DB::table('bot_quota_usage')->updateOrInsert(
                ['bot_id' => $bot->id, 'hour' => $currentHour],
                [
                    'requests_used' => DB::raw('requests_used + 1'),
                    'updated_at' => now(),
                ]
            );

            return [
                'allowed' => true,
                'used' => [
                    'requests' => 1,
                ],
                'remaining' => [
                    'requests' => $perBotHourlyRequests - ($botRequestsUsed + 1),
                ],
            ];
        });
    }

    /**
     * Get soft limit warning when approaching quota
     *
     * @param User $bot
     * @param Carbon $hour
     * @return array|null
     */
    public function getSoftLimitWarning(User $bot, Carbon $hour): ?array
    {
        $usage = DB::table('bot_quota_usage')
            ->where('bot_id', $bot->id)
            ->where('hour', $hour)
            ->first();

        if (!$usage) {
            return null;
        }

        $limit = config('ogame.bots.quotas.requests_per_bot_hourly', 12);
        $percent = ($usage->requests_used / $limit) * 100;

        if ($percent >= 95) {
            return [
                'warning' => true,
                'percent' => $percent,
                'remaining' => $limit - $usage->requests_used,
            ];
        }

        return null;
    }

    /**
     * Check quota without recording (for pre-flight checks)
     *
     * @param User $bot
     * @throws QuotaExceededException
     */
    public function checkQuota(User $bot): void
    {
        $currentHour = Carbon::now()->startOfHour();

        // Get per-bot request limit from config
        $perBotHourlyRequests = config('ogame.bots.quotas.requests_per_bot_hourly', 12);

        // Check per-bot hourly requests
        $botRequestsUsed = DB::table('bot_quota_usage')
            ->where('bot_id', $bot->id)
            ->where('hour', $currentHour)
            ->sum('requests_used');

        if ($botRequestsUsed + 1 > $perBotHourlyRequests) {
            throw new QuotaExceededException('requests', "Bot {$bot->id} exceeded hourly request quota ({$perBotHourlyRequests} requests/hour)");
        }
    }

    /**
     * Record usage (called after successful action)
     *
     * @param User $bot
     */
    public function recordUsage(User $bot): void
    {
        $currentHour = Carbon::now()->startOfHour();

        // Record per-bot usage
        DB::table('bot_quota_usage')->updateOrInsert(
            ['bot_id' => $bot->id, 'hour' => $currentHour],
            [
                'requests_used' => DB::raw('requests_used + 1'),
                'updated_at' => now(),
            ]
        );
    }
}
