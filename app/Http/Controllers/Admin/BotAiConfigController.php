<?php

namespace OGame\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use OGame\Http\Controllers\Controller;
use OGame\Models\BotAiConfig;

class BotAiConfigController extends Controller
{
    /**
     * Display list of bot AI configurations
     */
    public function index(): View
    {
        $configs = BotAiConfig::with('creator')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        // Add bot count to each config
        foreach ($configs as $config) {
            $config->setAttribute('bots_count', $config->bots()->count());
        }

        return view('admin.bot-configs.index', compact('configs'));
    }

    /**
     * Show create form
     */
    public function create(): View
    {
        return view('admin.bot-configs.create');
    }

    /**
     * Store new configuration
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:bot_ai_configs,name',
            'description' => 'nullable|string',
            'bot_ai_url' => 'required|url|max:500',
            'bot_ai_model' => 'required|string',
            'bot_ai_api_key' => 'required|string|max:2000',
            'is_active' => 'boolean',
        ]);

        $validated['created_by'] = Auth::id();
        $validated['is_active'] = $request->has('is_active');

        // Convert comma-separated models to array
        $validated['bot_ai_model'] = array_map('trim', explode(',', $validated['bot_ai_model']));

        BotAiConfig::create($validated);

        return redirect()->route('admin.bot-configs.index')
            ->with('success', 'Bot AI configuration created successfully');
    }

    /**
     * Show edit form
     */
    public function edit(BotAiConfig $botAiConfig): View
    {
        $botsCount = $botAiConfig->bots()->count();
        return view('admin.bot-configs.edit', compact('botAiConfig', 'botsCount'));
    }

    /**
     * Update configuration
     */
    public function update(Request $request, BotAiConfig $botAiConfig): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:bot_ai_configs,name,' . $botAiConfig->id,
            'description' => 'nullable|string',
            'bot_ai_url' => 'required|url|max:500',
            'bot_ai_model' => 'required|string',
            'bot_ai_api_key' => 'nullable|string|max:2000',
            'is_active' => 'boolean',
        ]);

        // Only update API key if provided
        if (empty($validated['bot_ai_api_key'])) {
            unset($validated['bot_ai_api_key']);
        }

        $validated['is_active'] = $request->has('is_active');

        // Convert comma-separated models to array
        $validated['bot_ai_model'] = array_map('trim', explode(',', $validated['bot_ai_model']));

        $botAiConfig->update($validated);

        return redirect()->route('admin.bot-configs.index')
            ->with('success', 'Bot AI configuration updated successfully');
    }

    /**
     * Delete configuration
     */
    public function destroy(BotAiConfig $botAiConfig): RedirectResponse
    {
        $botsCount = $botAiConfig->bots()->count();

        if ($botsCount > 0) {
            return redirect()->route('admin.bot-configs.index')
                ->with('error', "Cannot delete: {$botsCount} bots are using this configuration");
        }

        $botAiConfig->delete();

        return redirect()->route('admin.bot-configs.index')
            ->with('success', 'Bot AI configuration deleted successfully');
    }

    /**
     * Toggle active status (AJAX)
     */
    public function toggleActive(BotAiConfig $botAiConfig): JsonResponse
    {
        $botAiConfig->update(['is_active' => !$botAiConfig->is_active]);

        return response()->json([
            'success' => true,
            'is_active' => $botAiConfig->is_active,
        ]);
    }

    /**
     * Duplicate configuration
     */
    public function duplicate(BotAiConfig $botAiConfig): RedirectResponse
    {
        // Generate unique name with incremental number
        $baseName = $botAiConfig->name;
        $newName = $this->generateUniqueName($baseName);

        // Create duplicate
        $duplicate = BotAiConfig::create([
            'name' => $newName,
            'description' => $botAiConfig->description,
            'bot_ai_url' => $botAiConfig->bot_ai_url,
            'bot_ai_model' => $botAiConfig->bot_ai_model,
            'bot_ai_api_key' => $botAiConfig->bot_ai_api_key,
            'is_active' => true, // Duplicates start as active
            'created_by' => Auth::id(),
        ]);

        return redirect()->route('admin.bot-configs.edit', $duplicate)
            ->with('success', "Configuration duplicated as '{$newName}'");
    }

    /**
     * Generate unique name with incremental number
     */
    protected function generateUniqueName(string $baseName): string
    {
        // Remove existing number suffix if present
        $baseName = preg_replace('/ - \d+$/', '', $baseName);

        $counter = 2;
        $newName = "{$baseName} - {$counter}";

        // Keep incrementing until we find a unique name
        while (BotAiConfig::where('name', $newName)->exists()) {
            $counter++;
            $newName = "{$baseName} - {$counter}";
        }

        return $newName;
    }
}
