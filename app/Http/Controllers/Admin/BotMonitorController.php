<?php

namespace OGame\Http\Controllers\Admin;

use Illuminate\Http\Request;
use OGame\Http\Controllers\Controller;
use OGame\Models\User;
use Illuminate\Support\Facades\DB;

class BotMonitorController extends Controller
{
    /**
     * Show bot activity dashboard
     */
    public function dashboard()
    {
        $bots = User::where('is_bot', true)
            ->with('aiConfig')
            ->orderBy('username')
            ->get();

        // Enrich with activity data
        foreach ($bots as $bot) {
            // Last decision
            $lastDecision = DB::table('bot_decisions_active')
                ->where('user_id', $bot->id)
                ->orderBy('created_at', 'desc')
                ->first();
            $bot->last_decision = $lastDecision;

            // Decision count today
            $bot->decisions_today = DB::table('bot_decisions_active')
                ->where('user_id', $bot->id)
                ->where('created_at', '>=', now()->startOfDay())
                ->count();

            // Success rate (today only)
            $todayDecisions = DB::table('bot_decisions_active')
                ->where('user_id', $bot->id)
                ->where('created_at', '>=', now()->startOfDay())
                ->get();
            $bot->success_count = $todayDecisions->where('result', 'success')->count();
            $bot->total_recent = $todayDecisions->count();
        }

        return view('admin.bot-monitor.dashboard', compact('bots'));
    }

    /**
     * Show bot monitor dashboard
     */
    public function details(User $bot)
    {
        if (!$bot->is_bot) {
            return redirect()->route('admin.bot-monitor.dashboard')
                ->with('error', 'User is not a bot');
        }

        // Calculate statistics - ALL TIME
        $totalDecisionsAll = DB::table('bot_decisions_active')
            ->where('user_id', $bot->id)
            ->count();

        $successfulDecisionsAll = DB::table('bot_decisions_active')
            ->where('user_id', $bot->id)
            ->where('result', 'success')
            ->count();

        $successRateAll = $totalDecisionsAll > 0 ? ($successfulDecisionsAll / $totalDecisionsAll * 100) : 0;

        // Calculate statistics - TODAY
        $totalDecisionsToday = DB::table('bot_decisions_active')
            ->where('user_id', $bot->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        $successfulDecisionsToday = DB::table('bot_decisions_active')
            ->where('user_id', $bot->id)
            ->where('result', 'success')
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        $successRateToday = $totalDecisionsToday > 0 ? ($successfulDecisionsToday / $totalDecisionsToday * 100) : 0;

        // Quota usage - TODAY and ALL TIME
        $quotaUsageToday = DB::table('bot_quota_usage')
            ->where('bot_id', $bot->id)
            ->where('hour', '>=', now()->startOfDay())
            ->sum('requests_used');

        $quotaUsageAll = DB::table('bot_quota_usage')
            ->where('bot_id', $bot->id)
            ->sum('requests_used');

        // Get AI provider name
        $aiProvider = 'Unknown';
        if ($bot->bot_ai_config_id) {
            $config = \OGame\Models\BotAiConfig::find($bot->bot_ai_config_id);
            if ($config) {
                $aiProvider = $config->name;
            }
        }

        // Get last decision (most recent turn_id)
        $lastDecision = DB::table('bot_decisions_active')
            ->where('user_id', $bot->id)
            ->orderBy('created_at', 'desc')
            ->first();

        // Get all actions from the last API response (same turn_id)
        $lastActions = [];
        if ($lastDecision) {
            $lastActions = DB::table('bot_decisions_active')
                ->where('user_id', $bot->id)
                ->where('turn_id', $lastDecision->turn_id)
                ->orderBy('created_at', 'asc')
                ->get();
        }

        return view('admin.bot-monitor.details', compact(
            'bot',
            'totalDecisionsAll',
            'successfulDecisionsAll',
            'successRateAll',
            'totalDecisionsToday',
            'successfulDecisionsToday',
            'successRateToday',
            'quotaUsageToday',
            'quotaUsageAll',
            'aiProvider',
            'lastDecision',
            'lastActions'
        ));
    }

    /**
     * Build unified timeline from decisions, events, and logs
     * (Kept for potential future use)
     */
    protected function buildUnifiedTimelineOld($botId, $limit = 50)
    {
        $timeline = [];

        // Get decisions
        $decisions = DB::table('bot_decisions_active')
            ->where('user_id', $botId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        foreach ($decisions as $decision) {
            $icon = $decision->result === 'success' ? '✅' : ($decision->result === 'failed' ? '❌' : '⚠️');
            $color = $decision->result === 'success' ? '#4a7c4a' : ($decision->result === 'failed' ? '#7c4a4a' : '#f39c12');
            $bgColor = $decision->result === 'success' ? '#1a3a1a' : ($decision->result === 'failed' ? '#3a1a1a' : '#2a2a1a');

            $entry = [
                'timestamp' => $decision->created_at,
                'type' => 'decision',
                'type_label' => strtoupper($decision->action_type),
                'icon' => $icon,
                'color' => $color,
                'bg_color' => $bgColor,
                'target' => $decision->target,
            ];

            if ($decision->error_message) {
                $entry['error'] = $decision->error_message;
            }

            $timeline[] = $entry;
        }

        // Parse log file for API calls
        $logFile = storage_path('logs/laravel.log');
        if (file_exists($logFile)) {
            $command = "grep 'Bot {$botId} AI API' {$logFile} | tail -n 50";
            $logLines = shell_exec($command);

            if ($logLines) {
                $lines = explode("\n", trim($logLines));
                foreach ($lines as $line) {
                    if (empty($line)) {
                        continue;
                    }

                    // Parse API Request
                    if (strpos($line, 'AI API Request') !== false) {
                        if (preg_match('/\[(.*?)\]/', $line, $matches)) {
                            $timestamp = $matches[1];
                            $model = '';
                            $tokens = '';

                            if (preg_match('/"model":"([^"]+)"/', $line, $m)) {
                                $model = $m[1];
                            }
                            if (preg_match('/"estimated_tokens":(\d+)/', $line, $m)) {
                                $tokens = $m[1];
                            }

                            $timeline[] = [
                                'timestamp' => $timestamp,
                                'type' => 'api_request',
                                'type_label' => 'API Request',
                                'icon' => '📤',
                                'color' => '#00aaff',
                                'bg_color' => '#1a2a3a',
                                'details' => "Model: {$model} (~{$tokens} tokens)",
                            ];
                        }
                    }
                    // Parse API Response
                    elseif (strpos($line, 'AI API Response') !== false) {
                        if (preg_match('/\[(.*?)\]/', $line, $matches)) {
                            $timestamp = $matches[1];
                            $model = '';
                            $tokens = 0;
                            $cost = 0;

                            if (preg_match('/"model":"([^"]+)"/', $line, $m)) {
                                $model = $m[1];
                            }
                            if (preg_match('/"total":(\d+)/', $line, $m)) {
                                $tokens = $m[1];
                            }
                            if (preg_match('/"cost_usd":([\d.]+)/', $line, $m)) {
                                $cost = $m[1];
                            }

                            $timeline[] = [
                                'timestamp' => $timestamp,
                                'type' => 'api_response',
                                'type_label' => 'API Response',
                                'icon' => '📥',
                                'color' => '#00ff88',
                                'bg_color' => '#1a3a2a',
                                'details' => "Model: {$model}",
                                'tokens' => $tokens,
                                'cost' => $cost,
                            ];
                        }
                    }
                }
            }
        }

        // Sort by timestamp descending
        usort($timeline, function ($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });

        // Return only the requested limit
        return array_slice($timeline, 0, $limit);
    }

    /**
     * Get real-time update for a bot (AJAX)
     */
    public function getUpdate(User $bot)
    {
        if (!$bot->is_bot) {
            return response()->json(['error' => 'Not a bot'], 400);
        }

        // Last decision
        $lastDecision = DB::table('bot_decisions_active')
            ->where('user_id', $bot->id)
            ->orderBy('created_at', 'desc')
            ->first();

        return response()->json([
            'last_decision' => $lastDecision,
            'last_action' => $bot->bot_last_action,
            'last_heartbeat' => $bot->bot_last_heartbeat,
            'processing_until' => $bot->bot_processing_until,
            'enabled' => $bot->bot_enabled,
        ]);
    }
}
