<?php

namespace OGame\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use OGame\Http\Controllers\Controller;
use OGame\Models\BotAiConfig;
use OGame\Models\User;
use OGame\Services\Bot\BotService;

class BotController extends Controller
{
    /**
     * Display list of all bots
     */
    public function index()
    {
        $bots = User::where('is_bot', true)
            ->with(['aiConfig', 'backupAiConfig'])
            ->orderBy('username')
            ->paginate(15);

        return view('admin.bots.index', compact('bots'));
    }

    /**
     * Show create bot form
     */
    public function create()
    {
        $configs = BotAiConfig::where('is_active', true)
            ->orderBy('name')
            ->get();

        $randomNum = rand(1000, 9999);
        $defaultUsername = 'bot-player-' . $randomNum;
        $defaultEmail = 'bot' . $randomNum . '@bot.local';

        return view('admin.bots.create', compact('configs', 'defaultUsername', 'defaultEmail'));
    }

    /**
     * Store new bot
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string|max:100|unique:users,username',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:6|max:100',
            'bot_ai_config_model' => 'required|string',
            'backup_bot_ai_config_model' => 'nullable|string',
            'bot_skill_level' => 'required|in:beginner,intermediate,advanced,expert',
            'bot_strategy' => 'required|in:aggressive,balanced,defensive',
            'bot_enabled' => 'boolean',
            'bot_notes' => 'nullable|string|max:1000',
        ]);

        // Parse config_id and model from combined value
        [$configId, $model] = explode('|', $validated['bot_ai_config_model'], 2);
        $validated['bot_ai_config_id'] = $configId;
        $validated['bot_ai_model'] = $model;

        // Parse backup config_id and model if provided
        if (!empty($validated['backup_bot_ai_config_model'])) {
            [$backupConfigId, $backupModel] = explode('|', $validated['backup_bot_ai_config_model'], 2);
            $validated['backup_bot_ai_config_id'] = $backupConfigId;
            $validated['backup_bot_ai_model'] = $backupModel;
        } else {
            $validated['backup_bot_ai_config_id'] = null;
            $validated['backup_bot_ai_model'] = null;
        }

        // Map skill level names to integers (1-10)
        $skillLevelMap = [
            'beginner' => 3,
            'intermediate' => 5,
            'advanced' => 8,
            'expert' => 10,
        ];
        $skillLevel = $skillLevelMap[$validated['bot_skill_level']] ?? 5;

        try {
            // Use BotService to create bot with planet
            $botService = app(BotService::class);
            $bot = $botService->createBot(
                username: $validated['username'],
                skillLevel: $skillLevel,
                strategy: $validated['bot_strategy'],
                botAiConfigId: $validated['bot_ai_config_id']
            );

            // Update additional fields that BotService doesn't handle
            $bot->update([
                'email' => $validated['email'],
                'bot_ai_model' => $validated['bot_ai_model'],
                'backup_bot_ai_config_id' => $validated['backup_bot_ai_config_id'],
                'backup_bot_ai_model' => $validated['backup_bot_ai_model'],
                'password' => Hash::make($validated['password']),
                'bot_enabled' => $request->has('bot_enabled'),
                'bot_notes' => $validated['bot_notes'],
            ]);

            return redirect()->route('admin.bots.index')
                ->with('success', "Bot '{$validated['username']}' created successfully with starting planet");
        } catch (\Exception $e) {
            \Log::channel('bot')->error("Failed to create bot {$validated['username']}: " . $e->getMessage());
            return redirect()->back()
                ->withInput()
                ->with('error', 'Failed to create bot: ' . $e->getMessage());
        }
    }

    /**
     * Show edit bot form
     */
    public function edit(User $bot)
    {
        if (!$bot->is_bot) {
            return redirect()->route('admin.bots.index')
                ->with('error', 'User is not a bot');
        }

        $configs = BotAiConfig::orderBy('name')->get();
        return view('admin.bots.edit', compact('bot', 'configs'));
    }

    /**
     * Update bot settings
     */
    public function update(Request $request, User $bot)
    {
        if (!$bot->is_bot) {
            return redirect()->route('admin.bots.index')
                ->with('error', 'User is not a bot');
        }

        $validated = $request->validate([
            'bot_ai_config_model' => 'required|string',
            'backup_bot_ai_config_model' => 'nullable|string',
            'bot_skill_level' => 'required|in:beginner,intermediate,advanced,expert',
            'bot_strategy' => 'required|in:aggressive,balanced,defensive',
            'bot_enabled' => 'boolean',
            'bot_notes' => 'nullable|string|max:1000',
        ]);

        // Parse config_id and model from combined value
        [$configId, $model] = explode('|', $validated['bot_ai_config_model'], 2);
        $validated['bot_ai_config_id'] = $configId;
        $validated['bot_ai_model'] = $model;

        // Parse backup config_id and model if provided
        if (!empty($validated['backup_bot_ai_config_model'])) {
            [$backupConfigId, $backupModel] = explode('|', $validated['backup_bot_ai_config_model'], 2);
            $validated['backup_bot_ai_config_id'] = $backupConfigId;
            $validated['backup_bot_ai_model'] = $backupModel;
        } else {
            $validated['backup_bot_ai_config_id'] = null;
            $validated['backup_bot_ai_model'] = null;
        }

        // Map skill level names to integers (1-10)
        $skillLevelMap = [
            'beginner' => 3,
            'intermediate' => 5,
            'advanced' => 8,
            'expert' => 10,
        ];
        $validated['bot_skill_level'] = $skillLevelMap[$validated['bot_skill_level']] ?? 5;

        $validated['bot_enabled'] = $request->has('bot_enabled');

        $bot->update($validated);

        return redirect()->route('admin.bots.index')
            ->with('success', 'Bot updated successfully');
    }

    /**
     * Delete bot and all related data
     */
    public function destroy(User $bot)
    {
        if (!$bot->is_bot) {
            return redirect()->route('admin.bots.index')
                ->with('error', 'User is not a bot');
        }

        $botId = $bot->id;
        $botUsername = $bot->username;

        try {
            $botService = app(BotService::class);

            // Check for active fleets first
            $fleetCheck = $botService->checkActiveFleets($botId);

            if ($fleetCheck['has_fleets']) {
                return redirect()->route('admin.bots.index')
                    ->with('error', "Cannot delete bot '{$botUsername}': {$fleetCheck['count']} active fleet mission(s) in progress. Wait for fleets to return or use force delete.");
            }

            // Use BotService's comprehensive delete method
            $botService->deleteBot($botId, false);

            return redirect()->route('admin.bots.index')
                ->with('success', "Bot '{$botUsername}' and all related data deleted successfully");
        } catch (\Exception $e) {
            \Log::channel('bot')->error("Failed to delete bot {$botUsername}: " . $e->getMessage());
            return redirect()->route('admin.bots.index')
                ->with('error', 'Failed to delete bot: ' . $e->getMessage());
        }
    }

    /**
     * Force delete bot (cancels all active fleets)
     */
    public function forceDestroy(User $bot)
    {
        if (!$bot->is_bot) {
            return redirect()->route('admin.bots.index')
                ->with('error', 'User is not a bot');
        }

        $botId = $bot->id;
        $botUsername = $bot->username;

        try {
            $botService = app(BotService::class);

            // Force delete (cancels all active fleets)
            $botService->deleteBot($botId, true);

            return redirect()->route('admin.bots.index')
                ->with('success', "Bot '{$botUsername}' force deleted (all fleets canceled)");
        } catch (\Exception $e) {
            \Log::channel('bot')->error("Failed to force delete bot {$botUsername}: " . $e->getMessage());
            return redirect()->route('admin.bots.index')
                ->with('error', 'Failed to delete bot: ' . $e->getMessage());
        }
    }

    /**
     * Toggle bot enabled/disabled
     */
    public function toggleEnabled(User $bot)
    {
        if (!$bot->is_bot) {
            return response()->json(['error' => 'User is not a bot'], 400);
        }

        $bot->update(['bot_enabled' => !$bot->bot_enabled]);

        return response()->json([
            'success' => true,
            'bot_enabled' => $bot->bot_enabled,
        ]);
    }

    /**
     * Reset bot state
     */
    public function resetState(User $bot)
    {
        if (!$bot->is_bot) {
            return redirect()->route('admin.bots.edit', $bot)
                ->with('error', 'User is not a bot');
        }

        $bot->update([
            'bot_last_action' => null,
            'bot_processing_until' => null,
            'bot_last_heartbeat' => null,
        ]);

        return redirect()->route('admin.bots.edit', $bot)
            ->with('success', 'Bot state reset successfully');
    }
}
