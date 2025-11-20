<?php

namespace OGame\Services\Bot;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OGame\Exceptions\QuotaExceededException;
use OGame\Models\BotAiConfig;
use OGame\Models\User;

/**
 * Bot AI Service - Handles AI API calls and decision generation
 */
class BotAIService
{
    public function __construct(
        protected BotQuotaService $quotaService
    ) {}

    /**
     * Get AI decision for bot's turn
     * Requires AI configuration - no fallback
     */
    public function getDecision(User $bot, array $gameState, array $lastTurnSummary): array
    {
        // Load bot's AI config - REQUIRED
        if (!$bot->bot_ai_config_id) {
            Log::error("Bot {$bot->id} has no AI config assigned");
            throw new \Exception("Bot must have an AI configuration assigned");
        }

        $config = BotAiConfig::find($bot->bot_ai_config_id);
        if (!$config || !$config->is_active) {
            Log::error("Bot {$bot->id} AI config not found or inactive");
            throw new \Exception("Bot AI configuration not found or inactive");
        }

        // Build minified prompt
        $messages = $this->buildPrompt($bot->bot_skill_level, $bot->bot_strategy, $gameState, $lastTurnSummary);

        // Check quota (but don't record yet)
        try {
            $this->quotaService->checkQuota($bot);
        } catch (QuotaExceededException $e) {
            Log::warning("Bot {$bot->id} quota exceeded: " . $e->getMessage());
            // Log to bot file for visibility
            $this->logQuotaExceeded($bot->id, $e->getMessage());
            return ['actions' => [], 'overall_strategy' => 'Quota exceeded'];
        }

        // Use bot's selected model, or first model from config if not set
        $model = $bot->bot_ai_model;
        if (!$model && is_array($config->bot_ai_model) && !empty($config->bot_ai_model)) {
            $model = $config->bot_ai_model[0];
        }
        
        if (!$model) {
            throw new \Exception("No AI model configured for bot");
        }

        // Log prompt once before retries
        $this->writePromptToFile($bot->id, $model, $messages);
        
        // Try primary provider
        try {
            $decision = $this->callAPIWithConfig($bot->id, $config, $model, $messages, 'primary');
            
            // Record quota ONLY after successful API call AND valid response
            $this->quotaService->recordUsage($bot);
            
            return $decision;
        } catch (Exception $primaryException) {
            $primaryError = $primaryException->getMessage();
            Log::warning("Bot {$bot->id} primary AI provider failed: {$primaryError}");
            
            // Check if backup provider is configured
            if ($bot->backup_bot_ai_config_id) {
                $backupConfig = BotAiConfig::find($bot->backup_bot_ai_config_id);
                
                if ($backupConfig && $backupConfig->is_active) {
                    // Use bot's selected backup model, or first model from backup config if not set
                    $backupModel = $bot->backup_bot_ai_model;
                    if (!$backupModel && is_array($backupConfig->bot_ai_model) && !empty($backupConfig->bot_ai_model)) {
                        $backupModel = $backupConfig->bot_ai_model[0];
                    }
                    
                    if ($backupModel) {
                        Log::info("Bot {$bot->id} switching to backup AI provider (config: {$backupConfig->id}, model: {$backupModel})");
                        
                        // Log backup provider attempt to bot file
                        $this->writeBackupProviderSwitch($bot->id, $backupModel, $primaryError);
                        
                        try {
                            $decision = $this->callAPIWithConfig($bot->id, $backupConfig, $backupModel, $messages, 'backup');
                            
                            // Record quota ONLY after successful API call AND valid response
                            $this->quotaService->recordUsage($bot);
                            
                            return $decision;
                        } catch (Exception $backupException) {
                            $backupError = $backupException->getMessage();
                            Log::error("Bot {$bot->id} backup AI provider also failed: {$backupError}");
                            throw new \Exception("Both primary and backup AI providers failed. Primary: {$primaryError}. Backup: {$backupError}");
                        }
                    } else {
                        Log::error("Bot {$bot->id} backup config has no model configured");
                    }
                } else {
                    Log::warning("Bot {$bot->id} backup config not found or inactive (config_id: {$bot->backup_bot_ai_config_id})");
                }
            }
            
            // No backup configured or backup failed - re-throw primary exception
            throw $primaryException;
        }
    }

    /**
     * Call AI API with specific config and retry logic
     * 
     * @param int $botId Bot ID for logging
     * @param BotAiConfig $config AI configuration to use
     * @param string $model Model name to use
     * @param array $messages Messages to send
     * @param string $providerType 'primary' or 'backup' for logging
     * @return array Decision with metadata
     * @throws Exception if all retries fail or response is invalid
     */
    protected function callAPIWithConfig(int $botId, BotAiConfig $config, string $model, array $messages, string $providerType = 'primary'): array
    {
        $maxRetries = config('ogame.bots.ai.max_retries', 1);
        $lastError = 'Unknown error';
        
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                if ($attempt > 0) {
                    Log::info("Bot {$botId} {$providerType} provider retry attempt {$attempt}/{$maxRetries}");
                }
                
                $response = $this->callAPI($botId, $config->bot_ai_url, $config->bot_ai_api_key, $model, $messages);
                
                if ($response['success']) {
                    // Get raw response content
                    $rawContent = $response['data']['choices'][0]['message']['content'];
                    
                    // Preprocess: Remove <think> tags and other non-JSON content
                    $cleanedContent = $this->preprocessAIResponse($rawContent);
                    
                    // Parse and return decision
                    $decision = json_decode($cleanedContent, true);
                    
                    // Validate decision structure
                    if (!is_array($decision) || !isset($decision['actions'])) {
                        $lastError = "Invalid decision format from AI";
                        Log::error("Bot {$botId} {$providerType} attempt {$attempt}: {$lastError}", [
                            'raw_response' => $rawContent,
                            'cleaned_response' => $cleanedContent
                        ]);
                        throw new Exception($lastError);
                    }
                    
                    // Add default values for optional fields
                    $decision = array_merge([
                        'overall_strategy' => 'No strategy provided',
                    ], $decision);
                    
                    return $decision + ['_metadata' => ['tokens_used' => $response['tokens'], 'cost' => $response['cost'], 'provider' => $providerType]];
                } else {
                    // API call failed - log the error with raw response if available
                    $lastError = $response['error'] ?? 'API call failed';
                    Log::error("Bot {$botId} {$providerType} attempt {$attempt}: {$lastError}", [
                        'raw_response' => $response['raw_response'] ?? null,
                    ]);
                    
                    // Also log to bot file
                    if (isset($response['raw_response'])) {
                        $this->writeErrorResponseToFile($botId, $model, $lastError, $response['raw_response']);
                    }
                    
                    if ($attempt < $maxRetries) {
                        sleep(pow(2, $attempt)); // Exponential backoff
                    }
                }
            } catch (Exception $e) {
                $lastError = $e->getMessage();
                Log::error("Bot {$botId} {$providerType} attempt {$attempt}: {$lastError}");
                
                if ($attempt < $maxRetries) {
                    sleep(pow(2, $attempt)); // Exponential backoff
                }
            }
        }

        // All retries failed - throw exception with last error
        Log::error("Bot {$botId} {$providerType} provider all retries failed. Last error: {$lastError}");
        throw new \Exception("{$providerType} AI provider failed after {$maxRetries} retries: {$lastError}");
    }

    /**
     * Detect API provider type from configuration URL
     * 
     * @param string $apiUrl The API base URL from bot configuration
     * @return string Provider type: 'gemini' or 'openai'
     */
    protected function detectProvider(string $apiUrl): string
    {
        $urlLower = strtolower($apiUrl);
        
        // Check for Gemini indicators
        if (str_contains($urlLower, 'generativelanguage.googleapis.com') || 
            str_contains($urlLower, 'gemini')) {
            return 'gemini';
        }
        
        // Default to OpenAI-compatible for backward compatibility
        return 'openai';
    }

    /**
     * Transform OpenAI-style messages to Gemini format
     * 
     * @param array $messages OpenAI-style messages with role and content
     * @return array Gemini-formatted request body with contents and generationConfig
     */
    protected function transformMessagesToGemini(array $messages): array
    {
        $systemMessage = null;
        $transformedMessages = [];
        
        // Extract system message if present
        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'system') {
                $systemMessage = $message['content'] ?? '';
            } else {
                $transformedMessages[] = $message;
            }
        }
        
        // If we have a system message, prepend it to the first user message
        if ($systemMessage !== null && !empty($transformedMessages)) {
            $firstMessage = $transformedMessages[0];
            if (($firstMessage['role'] ?? '') === 'user') {
                $transformedMessages[0]['content'] = $systemMessage . "\n\n" . ($firstMessage['content'] ?? '');
            }
        }
        
        // Convert to Gemini format and combine consecutive same-role messages
        $geminiContents = [];
        $currentRole = null;
        $currentParts = [];
        
        foreach ($transformedMessages as $message) {
            $role = $message['role'] ?? '';
            $content = $message['content'] ?? '';
            
            // Map OpenAI roles to Gemini roles
            $geminiRole = match($role) {
                'user' => 'user',
                'assistant' => 'model',
                default => 'user', // Default to user for unknown roles
            };
            
            // If role changes, save the current accumulated message
            if ($currentRole !== null && $currentRole !== $geminiRole) {
                $geminiContents[] = [
                    'role' => $currentRole,
                    'parts' => $currentParts,
                ];
                $currentParts = [];
            }
            
            // Add content to current parts
            $currentParts[] = ['text' => $content];
            $currentRole = $geminiRole;
        }
        
        // Add the last accumulated message
        if ($currentRole !== null && !empty($currentParts)) {
            $geminiContents[] = [
                'role' => $currentRole,
                'parts' => $currentParts,
            ];
        }
        
        // Return Gemini-formatted request body
        return [
            'contents' => $geminiContents,
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 20000,
            ],
        ];
    }

    /**
     * Build comprehensive prompt for AI API
     */
    protected function buildPrompt(int $skillLevel, string $strategy, array $gameState, array $lastTurnSummary): array
    {
        // Validate game state for consistency before generating prompts
        // Also get warnings to include in prompt for AI consideration
        $warnings = GameStateValidator::getWarnings($gameState);
        $validatedGameState = GameStateValidator::validate($gameState);
        
        $systemPrompt = $this->buildSystemPrompt($strategy, $skillLevel);
        $userPrompt = $this->buildUserPrompt($validatedGameState, $lastTurnSummary, $warnings);
        
        return [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];
    }

    /**
     * Build detailed system prompt based on strategy
     * Organized with consistent === SECTION === formatting for clarity
     */
    protected function buildSystemPrompt(string $strategy, int $skillLevel): string
    {
        // Build all sections with consistent formatting
        $sections = [];
        
        // 1. ROLE - Who you are and what you do
        $sections[] = "=== ROLE ===\n" .
            "You are an OGame AI (skill {$skillLevel}/10, {$strategy} strategy).\n" .
            "Analyze game state → return valid actions as JSON.\n";
        
        // 2. STRATEGY PRIORITIES - Strategy-specific guidance
        $strategyGuides = [
            'miner' => "PRIORITIES: mines > storage > research_lab > energy\nRatio: Metal:Crystal:Deuterium = 2:1.5:1",
            'raider' => "PRIORITIES: economy + shipyard > light_fighter > espionage\nBalance production with fleet.",
            'defender' => "PRIORITIES: defenses > storage > fleet_save\nBuild rocket_launcher, light_laser early.",
            'balanced' => "PRIORITIES: adapt to situation\nEconomy first, then research, then military as needed.",
        ];
        
        $sections[] = "=== STRATEGY PRIORITIES ===\n" .
            ($strategyGuides[$strategy] ?? $strategyGuides['balanced']);
        
        // 3. OUTPUT FORMAT - How to respond
        $sections[] = "=== OUTPUT FORMAT ===\n" .
            "JSON only, no markdown.\n" .
            "Required fields:\n" .
            "- actions: array of actions to take\n" .
            "- overall_strategy: brief explanation of your strategy this turn (1 sentence)\n\n" .
            "Example: {\"actions\":[{\"action_type\":\"BUILD_BUILDING\",\"planet_id\":7,\"target\":\"metal_mine\",\"quantity\":1}],\"overall_strategy\":\"Focusing on metal production to support fleet building\"}\n" .
            "Empty: {\"actions\":[],\"overall_strategy\":\"Waiting for resources to accumulate\"}\n\n" .
            "ACTION LIMITS (max actions per turn - MAXIMIZE when possible):\n" .
            "- BUILD_BUILDING(3): Up to 3 different buildings across all planets\n" .
            "  Required: action_type, planet_id, target, quantity\n" .
            "- START_RESEARCH(3): Up to 3 different research projects\n" .
            "  Required: action_type, planet_id, target\n" .
            "  NOTE: MUST include planet_id - research happens on the specified planet's research lab\n" .
            "- BUILD_UNITS(2): Up to 2 different unit types (any quantity each)\n" .
            "  Required: action_type, planet_id, target, quantity\n" .
            "- SEND_FLEET(3): Up to 3 fleet missions (any ships per mission)\n" .
            "  Required: action_type, planet_id, target_coords, ships, mission_type\n\n" .
            "IMPORTANT: When queues are available and resources allow, MAXIMIZE actions.\n" .
            "See DECISION RULES section for details on when and how to maximize actions.";
        
        // 4. AVAILABLE ACTIONS - What you can do
        $sections[] = "=== AVAILABLE ACTIONS ===\n" .
            "BUILD_BUILDING: metal_mine, crystal_mine, deuterium_synthesizer, solar_plant, fusion_plant, metal_store, crystal_store, deuterium_store, research_lab, robotics_factory, shipyard, missile_silo, nanite_factory\n\n" .
            "START_RESEARCH: energy_technology, combustion_drive, laser_technology, weapon_technology, shielding_technology, armor_technology, computer_technology, espionage_technology, astrophysics (requires research_lab)\n\n" .
            "BUILD_UNITS: light_fighter, heavy_fighter, cruiser, small_cargo, large_cargo, espionage_probe, rocket_launcher, light_laser (requires shipyard for ships)";
        
        // 5. RESOURCE COSTS - What things cost
        $sections[] = $this->buildResourceCostsSection();
        
        // 6. PREREQUISITES - What you need before building
        $sections[] = $this->buildPrerequisitesSection();
        
        // 7. DECISION RULES - Rules to follow
        $sections[] = "=== DECISION RULES (Sequential) ===\n\n" .
            "BLOCKING RULES (prevent specific actions):\n" .
            "1. IF build_queue_busy=true → CANNOT BUILD_BUILDING (but can do other actions)\n" .
            "2. IF research_queue_busy=true → CANNOT START_RESEARCH (but can do other actions)\n" .
            "3. IF unit_queue_busy=true → CANNOT BUILD_UNITS (but can do other actions)\n" .
            "4. IF research_lab_available=false → CANNOT START_RESEARCH\n" .
            "5. IF shipyard_available=false → CANNOT build ships\n\n" .
            "PRIORITY RULES (guide action selection):\n" .
            "6. IF energy < 0 → CRITICAL PRIORITY (MUST FIX IMMEDIATELY):\n" .
            "   - Energy deficit reduces production by 50% - this is CRITICAL and must be fixed ASAP\n" .
            "   - PRIMARY: Build solar_plant (if affordable AND build_queue not busy)\n" .
            "   - FALLBACK 1: Build fusion_plant (if affordable AND fusion_plant requirements met AND build_queue not busy)\n" .
            "   - FALLBACK 2: If build_queue busy, propose START_RESEARCH or BUILD_UNITS to use time productively\n" .
            "   - FALLBACK 3: If not affordable, return empty actions and wait for resources\n" .
            "   - NOTE: Check affordability by comparing solar_plant cost (75M, 30C) vs available resources\n" .
            "   - IMPORTANT: If you see GAME STATE ALERTS about energy deficit, this MUST be your TOP PRIORITY\n" .
            "   - Energy deficit warnings mean production is being wasted - fix energy FIRST before other actions\n\n" .
            "7. IF any storage > 90% full → HIGH PRIORITY (MAXIMIZE ACTIONS):\n" .
            "   - CRITICAL: Use MULTIPLE actions to spend resources efficiently\n" .
            "   - PRIORITY 1 (SPEND): Spend excess resources through MULTIPLE actions:\n" .
            "     * Combine START_RESEARCH + BUILD_UNITS + BUILD_BUILDING if all queues free\n" .
            "     * Example: If storage full, queues free, resources available:\n" .
            "       → START_RESEARCH (energy_technology) + BUILD_UNITS (small_cargo) + BUILD_BUILDING (metal_store)\n" .
            "     * Check affordability: Can we afford multiple research/units/buildings?\n" .
            "     * Use up to limits: 3 research + 2 units + 3 buildings = up to 8 actions total\n" .
            "   - PRIORITY 2 (BUILD STORAGE): If spending not possible, build appropriate storage\n" .
            "     * metal_store if metal > 90% (cost: 1000M)\n" .
            "     * crystal_store if crystal > 90% (cost: 1000M, 500C)\n" .
            "     * deuterium_store if deuterium > 90% (cost: 1000M, 1000C, 1000D)\n" .
            "     * Only if affordable AND build_queue not busy\n" .
            "   - FALLBACK: If neither spending nor storage building possible, wait for queue to free up\n\n" .
            "VALIDATION RULES:\n" .
            "8. CHECK affordability: Only propose actions where resources >= cost\n" .
            "   - Calculate cost for target level using formula: base_cost × factor^(level-1)\n" .
            "   - Compare against available resources on planet\n" .
            "   - If not affordable, skip this action and try alternatives\n\n" .
            "9. CHECK prerequisites: Only propose actions where requirements are met\n" .
            "   - BUILD_BUILDING: Verify all required buildings exist at required levels\n" .
            "   - START_RESEARCH: Verify research_lab level and required research completed\n" .
            "   - BUILD_UNITS: CRITICAL - Verify shipyard level AND research levels match requirements\n" .
            "     * Example: large_cargo needs shipyard(4) AND combustion_drive(6)\n" .
            "     * Check current shipyard level in Buildings section\n" .
            "     * Check current research levels in Technologies section\n" .
            "     * If shipyard or research level too low, DO NOT propose that unit\n" .
            "   - If prerequisites not met, skip this action\n\n" .
            "10. IF last turn failed same action → DON'T repeat\n" .
            "   - Check last turn summary for failed actions\n" .
            "   - Avoid proposing the exact same action (same type, target, planet)\n" .
            "   - Try alternative actions instead\n\n" .
            "WAIT CONDITIONS (return empty actions array):\n" .
            "- All queues busy (build_queue_busy AND research_queue_busy AND unit_queue_busy)\n" .
            "- Not enough resources for ANY available action\n" .
            "- Last 2 turns failed same action";
        
        // 8. DECISION PROCESS - Step-by-step guide
        $sections[] = $this->buildDecisionProcessSection();
        
        // Join all sections with double newlines for readability
        return implode("\n\n", $sections) . "\n";
    }



    /**
     * Build resource costs section for system prompt
     * Provides comprehensive cost information for all buildings, research, and units
     */
    protected function buildResourceCostsSection(): string
    {
        $section = "=== RESOURCE COSTS (Level 1) ===\n\n";
        
        // Buildings
        $section .= "BUILDINGS:\n";
        $buildingCosts = ResourceCostProvider::getBuildingCosts();
        foreach ($buildingCosts as $building => $cost) {
            $parts = [];
            if ($cost['metal'] > 0) $parts[] = number_format($cost['metal']) . 'M';
            if ($cost['crystal'] > 0) $parts[] = number_format($cost['crystal']) . 'C';
            if ($cost['deuterium'] > 0) $parts[] = number_format($cost['deuterium']) . 'D';
            $section .= "  {$building}: " . implode(', ', $parts) . "\n";
        }
        
        $section .= "\nRESEARCH:\n";
        $researchCosts = ResourceCostProvider::getResearchCosts();
        foreach ($researchCosts as $research => $cost) {
            $parts = [];
            if ($cost['metal'] > 0) $parts[] = number_format($cost['metal']) . 'M';
            if ($cost['crystal'] > 0) $parts[] = number_format($cost['crystal']) . 'C';
            if ($cost['deuterium'] > 0) $parts[] = number_format($cost['deuterium']) . 'D';
            $section .= "  {$research}: " . implode(', ', $parts) . "\n";
        }
        
        $section .= "\nUNITS & SHIPS:\n";
        $unitCosts = ResourceCostProvider::getUnitCosts();
        foreach ($unitCosts as $unit => $cost) {
            $parts = [];
            if ($cost['metal'] > 0) $parts[] = number_format($cost['metal']) . 'M';
            if ($cost['crystal'] > 0) $parts[] = number_format($cost['crystal']) . 'C';
            if ($cost['deuterium'] > 0) $parts[] = number_format($cost['deuterium']) . 'D';
            $section .= "  {$unit}: " . implode(', ', $parts) . "\n";
        }
        
        $section .= "\nCOST FORMULA:\n";
        $section .= "Buildings/Research: cost(level) = base_cost × factor^(level-1)\n";
        $section .= "Most buildings use factor 2.0, mines use 1.5-1.6\n";
        $section .= "Example: metal_mine level 5 = 60M × 1.5^4 = 304M, 76C\n\n";
        
        return $section;
    }

    /**
     * Build prerequisites section for system prompt
     * Provides information about building, research, and unit requirements
     * Uses actual GameObject data instead of hard-coded values
     */
    protected function buildPrerequisitesSection(): string
    {
        $section = "=== PREREQUISITES ===\n";
        
        // Building prerequisites (key buildings only)
        $section .= "BUILDINGS:\n";
        $keyBuildings = ['shipyard', 'missile_silo', 'nanite_factory', 'terraformer', 'space_dock'];
        $allBuildings = [...\OGame\Services\ObjectService::getBuildingObjects(), 
                         ...\OGame\Services\ObjectService::getStationObjects()];
        
        foreach ($keyBuildings as $buildingName) {
            foreach ($allBuildings as $building) {
                if ($building->machine_name === $buildingName && !empty($building->requirements)) {
                    $prereqParts = [];
                    foreach ($building->requirements as $req) {
                        $prereqParts[] = "{$req->object_machine_name}({$req->level})";
                    }
                    if (!empty($prereqParts)) {
                        $section .= "  {$buildingName}: " . implode(', ', $prereqParts) . "\n";
                    }
                }
            }
        }
        $section .= "  Others (mines, storage, research_lab): none\n";
        
        // Research prerequisites (from actual GameObject data)
        $section .= "\nRESEARCH:\n";
        $allResearch = \OGame\Services\ObjectService::getResearchObjects();
        
        foreach ($allResearch as $research) {
            $prereqParts = [];
            foreach ($research->requirements as $req) {
                // Check if it's research_lab requirement (building) or research requirement
                if ($req->object_machine_name === 'research_lab') {
                    $prereqParts[] = "lab({$req->level})";
                } else {
                    $prereqParts[] = "{$req->object_machine_name}({$req->level})";
                }
            }
            if (!empty($prereqParts)) {
                $section .= "  {$research->machine_name}: " . implode(', ', $prereqParts) . "\n";
            }
        }
        
        // Unit prerequisites (from actual GameObject data)
        $section .= "\nUNITS:\n";
        $allUnits = \OGame\Services\ObjectService::getUnitObjects();
        
        // List common units that are often used (prioritize these)
        $commonUnits = ['light_fighter', 'small_cargo', 'large_cargo', 'espionage_probe', 
                       'heavy_fighter', 'cruiser', 'recycler', 'colony_ship', 'solar_satellite',
                       'rocket_launcher', 'light_laser'];
        
        // First add common units
        foreach ($commonUnits as $unitName) {
            foreach ($allUnits as $unit) {
                if ($unit->machine_name === $unitName && !empty($unit->requirements)) {
                    $prereqParts = [];
                    foreach ($unit->requirements as $req) {
                        $prereqParts[] = "{$req->object_machine_name}({$req->level})";
                    }
                    if (!empty($prereqParts)) {
                        $section .= "  {$unitName}: " . implode(', ', $prereqParts) . "\n";
                    }
                }
            }
        }
        
        $section .= "  Other units: check shipyard level and research requirements";
        
        return $section;
    }

    /**
     * Build decision process guide section for system prompt
     * Provides a clear 7-step process for AI to follow when making decisions
     */
    protected function buildDecisionProcessSection(): string
    {
        $section = "=== DECISION PROCESS ===\n";
        $section .= "Follow these steps:\n\n";
        
        $section .= "1. Identify Available Queues\n";
        $section .= "   Check which queues NOT busy. Busy queues block ONLY their action type.\n\n";
        
        $section .= "2. List Possible Actions\n";
        $section .= "   For each available queue, list all possible actions.\n\n";
        
        $section .= "3. Filter by Prerequisites\n";
        $section .= "   Remove actions where requirements not met.\n";
        $section .= "   For BUILD_UNITS: Check shipyard level AND research levels from game state.\n";
        $section .= "   Example: large_cargo requires shipyard(4) + combustion_drive(6).\n\n";
        
        $section .= "4. Filter by Affordability\n";
        $section .= "   Calculate cost (base × factor^(level-1)). Remove if resources < cost.\n\n";
        
        $section .= "5. Apply Priority Rules\n";
        $section .= "   Follow DECISION RULES section above (energy deficit = CRITICAL, storage full = HIGH priority).\n\n";
        
        $section .= "6. Select Best Actions (MAXIMIZE when possible)\n";
        $section .= "   Choose based on strategy. Don't repeat failed actions.\n";
        $section .= "   See DECISION RULES section for details on maximizing actions when storage is full.\n\n";
        
        $section .= "7. Return JSON\n";
        $section .= "   Format: {\"actions\":[...]} or {\"actions\":[]} if nothing available.\n\n";
        
        $section .= "EXAMPLE 1: Planet 1000M, 500C, 0D | energy -50 | build_queue free\n";
        $section .= "→ Apply Priority Rule 6 (energy deficit) → BUILD solar_plant\n\n";
        $section .= "EXAMPLE 2: Storage 100% full | queues all free | resources: 50000M, 30000C, 10000D\n";
        $section .= "→ Apply Priority Rule 7 (storage full) → MAXIMIZE with multiple actions";
        
        return $section;
    }

    /**
     * Build affordability section showing which actions are affordable on each planet
     * Helps AI make informed decisions by showing resource constraints
     */
    protected function buildAffordabilitySection(array $gameState): string
    {
        $section = "=== AFFORDABILITY STATUS ===\n\n";
        
        foreach ($gameState['planets'] ?? [] as $planet) {
            $section .= "Planet {$planet['planet_id']}: {$planet['name']}\n";
            $section .= "  Available: {$planet['metal_stored']}M, {$planet['crystal_stored']}C, {$planet['deuterium_stored']}D\n";
            
            // Check BUILD_BUILDING affordability
            if (!$planet['build_queue_busy']) {
                $section .= "  BUILD_BUILDING (queue available):\n";
                $affordableBuildings = [];
                $unaffordableBuildings = [];
                
                // Check common buildings
                $buildingsToCheck = ['metal_mine', 'crystal_mine', 'deuterium_synthesizer', 'solar_plant', 
                                     'metal_store', 'crystal_store', 'deuterium_store', 
                                     'research_lab', 'robot_factory', 'shipyard'];
                
                foreach ($buildingsToCheck as $building) {
                    // Find current level
                    $currentLevel = 0;
                    foreach ($planet['buildings'] ?? [] as $b) {
                        if ($b['type'] === $building) {
                            $currentLevel = $b['level'];
                            break;
                        }
                    }
                    
                    $nextLevel = $currentLevel + 1;
                    $cost = ResourceCostProvider::calculateBuildingCost($building, $nextLevel);
                    
                    $isAffordable = $planet['metal_stored'] >= $cost['metal'] &&
                                   $planet['crystal_stored'] >= $cost['crystal'] &&
                                   $planet['deuterium_stored'] >= $cost['deuterium'];
                    
                    if ($isAffordable) {
                        $affordableBuildings[] = "{$building}({$nextLevel})";
                    } else {
                        $missing = [];
                        if ($planet['metal_stored'] < $cost['metal']) {
                            $missing[] = 'need ' . ($cost['metal'] - $planet['metal_stored']) . 'M';
                        }
                        if ($planet['crystal_stored'] < $cost['crystal']) {
                            $missing[] = 'need ' . ($cost['crystal'] - $planet['crystal_stored']) . 'C';
                        }
                        if ($planet['deuterium_stored'] < $cost['deuterium']) {
                            $missing[] = 'need ' . ($cost['deuterium'] - $planet['deuterium_stored']) . 'D';
                        }
                        $unaffordableBuildings[] = "{$building}({$nextLevel}): " . implode(', ', $missing);
                    }
                }
                
                if (!empty($affordableBuildings)) {
                    $section .= "    ✓ AFFORDABLE: " . implode(', ', $affordableBuildings) . "\n";
                } else {
                    $section .= "    ✗ NONE AFFORDABLE\n";
                }
                
                // Show a few unaffordable examples (limit to 3 to save space)
                if (!empty($unaffordableBuildings)) {
                    $section .= "    ✗ NOT AFFORDABLE (examples): " . implode(' | ', array_slice($unaffordableBuildings, 0, 3)) . "\n";
                }
            } else {
                $section .= "  BUILD_BUILDING: ✗ BLOCKED (queue busy)\n";
            }
            
            // Check BUILD_UNITS affordability (only if shipyard exists)
            $hasShipyard = false;
            foreach ($planet['buildings'] ?? [] as $building) {
                if ($building['type'] === 'shipyard' && $building['level'] > 0) {
                    $hasShipyard = true;
                    break;
                }
            }
            
            if ($hasShipyard && !$planet['unit_queue_busy']) {
                $section .= "  BUILD_UNITS (queue available, shipyard exists):\n";
                $affordableUnits = [];
                $unaffordableUnits = [];
                
                // Get unit prerequisites for requirement checking
                $unitPrereqs = ResourceCostProvider::getUnitPrerequisites();
                
                // Get shipyard level
                $shipyardLevel = 0;
                foreach ($planet['buildings'] ?? [] as $building) {
                    if ($building['type'] === 'shipyard') {
                        $shipyardLevel = $building['level'];
                        break;
                    }
                }
                
                // Get research levels (from gameState, which is passed to this method)
                $researchLevels = [];
                if (isset($gameState['research']['technologies'])) {
                    foreach ($gameState['research']['technologies'] as $tech) {
                        $researchLevels[$tech['tech_type']] = $tech['current_level'];
                    }
                }
                
                // Check common units
                $unitsToCheck = ['light_fighter', 'heavy_fighter', 'cruiser', 'small_cargo', 
                                'large_cargo', 'espionage_probe', 'rocket_launcher', 'light_laser'];
                
                foreach ($unitsToCheck as $unit) {
                    $cost = ResourceCostProvider::getUnitCosts()[$unit] ?? null;
                    if (!$cost) continue;
                    
                    // Check resources
                    $hasResources = $planet['metal_stored'] >= $cost['metal'] &&
                                   $planet['crystal_stored'] >= $cost['crystal'] &&
                                   $planet['deuterium_stored'] >= $cost['deuterium'];
                    
                    // Check requirements
                    $requirementsMet = true;
                    $missingReqs = [];
                    
                    if (isset($unitPrereqs[$unit])) {
                        $prereq = $unitPrereqs[$unit];
                        
                        // Check shipyard requirement
                        if (isset($prereq['shipyard']) && $shipyardLevel < $prereq['shipyard']) {
                            $requirementsMet = false;
                            $missingReqs[] = "shipyard({$prereq['shipyard']})";
                        }
                        
                        // Check research requirements
                        if (isset($prereq['requirements']) && !empty($prereq['requirements'])) {
                            foreach ($prereq['requirements'] as $req) {
                                $currentLevel = $researchLevels[$req['type']] ?? 0;
                                if ($currentLevel < $req['level']) {
                                    $requirementsMet = false;
                                    $missingReqs[] = "{$req['type']}({$req['level']})";
                                }
                            }
                        }
                    }
                    
                    // Unit is affordable only if both resources AND requirements are met
                    if ($hasResources && $requirementsMet) {
                        $affordableUnits[] = $unit;
                    } else {
                        $missing = [];
                        if (!$hasResources) {
                            if ($planet['metal_stored'] < $cost['metal']) {
                                $missing[] = 'need ' . ($cost['metal'] - $planet['metal_stored']) . 'M';
                            }
                            if ($planet['crystal_stored'] < $cost['crystal']) {
                                $missing[] = 'need ' . ($cost['crystal'] - $planet['crystal_stored']) . 'C';
                            }
                            if ($planet['deuterium_stored'] < $cost['deuterium']) {
                                $missing[] = 'need ' . ($cost['deuterium'] - $planet['deuterium_stored']) . 'D';
                            }
                        }
                        if (!$requirementsMet) {
                            $missing[] = 'requirements: ' . implode(', ', $missingReqs);
                        }
                        $unaffordableUnits[] = "{$unit}: " . implode(', ', $missing);
                    }
                }
                
                if (!empty($affordableUnits)) {
                    $section .= "    ✓ AFFORDABLE: " . implode(', ', $affordableUnits) . "\n";
                } else {
                    $section .= "    ✗ NONE AFFORDABLE\n";
                }
                
                // Show a few unaffordable examples
                if (!empty($unaffordableUnits)) {
                    $section .= "    ✗ NOT AFFORDABLE (examples): " . implode(' | ', array_slice($unaffordableUnits, 0, 3)) . "\n";
                }
            } elseif (!$hasShipyard) {
                $section .= "  BUILD_UNITS: ✗ BLOCKED (no shipyard)\n";
            } else {
                $section .= "  BUILD_UNITS: ✗ BLOCKED (queue busy)\n";
            }
            
            $section .= "\n";
        }
        
        // Check START_RESEARCH affordability (empire-wide)
        $hasResearchLab = false;
        $maxResearchLabLevel = 0;
        foreach ($gameState['planets'] ?? [] as $planet) {
            foreach ($planet['buildings'] ?? [] as $building) {
                if ($building['type'] === 'research_lab' && $building['level'] > 0) {
                    $hasResearchLab = true;
                    $maxResearchLabLevel = max($maxResearchLabLevel, $building['level']);
                }
            }
        }
        
        $researchQueueBusy = $gameState['research']['research_queue_busy'] ?? false;
        
        if ($hasResearchLab && !$researchQueueBusy) {
            $section .= "START_RESEARCH (empire-wide, queue available):\n";
            
            // Calculate total empire resources (sum across all planets)
            $totalMetal = 0;
            $totalCrystal = 0;
            $totalDeuterium = 0;
            foreach ($gameState['planets'] ?? [] as $planet) {
                $totalMetal += $planet['metal_stored'];
                $totalCrystal += $planet['crystal_stored'];
                $totalDeuterium += $planet['deuterium_stored'];
            }
            
            $section .= "  Total empire resources: {$totalMetal}M, {$totalCrystal}C, {$totalDeuterium}D\n";
            
            $affordableResearch = [];
            $unaffordableResearch = [];
            
            // Check common research
            $researchToCheck = ['energy_technology', 'combustion_drive', 'laser_technology', 
                               'weapon_technology', 'shielding_technology', 'armor_technology',
                               'computer_technology', 'espionage_technology', 'astrophysics'];
            
            foreach ($researchToCheck as $research) {
                // Find current level
                $currentLevel = 0;
                foreach ($gameState['research']['technologies'] ?? [] as $tech) {
                    if ($tech['tech_type'] === $research) {
                        $currentLevel = $tech['current_level'];
                        break;
                    }
                }
                
                $nextLevel = $currentLevel + 1;
                $cost = ResourceCostProvider::calculateResearchCost($research, $nextLevel);
                
                $isAffordable = $totalMetal >= $cost['metal'] &&
                               $totalCrystal >= $cost['crystal'] &&
                               $totalDeuterium >= $cost['deuterium'];
                
                if ($isAffordable) {
                    $affordableResearch[] = "{$research}({$nextLevel})";
                } else {
                    $missing = [];
                    if ($totalMetal < $cost['metal']) {
                        $missing[] = 'need ' . ($cost['metal'] - $totalMetal) . 'M';
                    }
                    if ($totalCrystal < $cost['crystal']) {
                        $missing[] = 'need ' . ($cost['crystal'] - $totalCrystal) . 'C';
                    }
                    if ($totalDeuterium < $cost['deuterium']) {
                        $missing[] = 'need ' . ($cost['deuterium'] - $totalDeuterium) . 'D';
                    }
                    $unaffordableResearch[] = "{$research}({$nextLevel}): " . implode(', ', $missing);
                }
            }
            
            if (!empty($affordableResearch)) {
                $section .= "  ✓ AFFORDABLE: " . implode(', ', $affordableResearch) . "\n";
            } else {
                $section .= "  ✗ NONE AFFORDABLE\n";
            }
            
            // Show a few unaffordable examples
            if (!empty($unaffordableResearch)) {
                $section .= "  ✗ NOT AFFORDABLE (examples): " . implode(' | ', array_slice($unaffordableResearch, 0, 3)) . "\n";
            }
        } else {
            if (!$hasResearchLab) {
                $section .= "START_RESEARCH: ✗ BLOCKED (no research_lab)\n";
            } else {
                $section .= "START_RESEARCH: ✗ BLOCKED (queue busy)\n";
            }
        }
        
        $section .= "\n";
        $section .= "NOTE: Use this affordability information to make informed decisions.\n";
        $section .= "Only propose actions that are marked as AFFORDABLE (✓).\n";
        $section .= "If nothing is affordable, return empty actions array and wait for resources.\n\n";
        
        return $section;
    }

    /**
     * Build user prompt with game state
     */
    protected function buildUserPrompt(array $gameState, array $lastTurnSummary, array $validationWarnings = []): string
    {
        $prompt = "=== MISSION PARAMETERS ===\n";
        $prompt .= "Strategy: {$gameState['strategy']}\n";
        $prompt .= "Skill Level: {$gameState['skill_level']}/10\n";
        $prompt .= "\n";
        
        // Add validation warnings if present - important for AI to know about data issues
        if (!empty($validationWarnings)) {
            $prompt .= "⚠️ GAME STATE ALERTS (CRITICAL - MUST ADDRESS IMMEDIATELY):\n";
            
            // Separate energy-related warnings (highest priority)
            $energyWarnings = [];
            $otherWarnings = [];
            
            foreach ($validationWarnings as $warning) {
                if (stripos($warning, 'energy') !== false || stripos($warning, 'Energy') !== false) {
                    $energyWarnings[] = $warning;
                } else {
                    $otherWarnings[] = $warning;
                }
            }
            
            // Show energy warnings first with emphasis
            if (!empty($energyWarnings)) {
                $prompt .= "\n🚨 CRITICAL ENERGY DEFICIT DETECTED - TOP PRIORITY:\n";
                $prompt .= "   Energy deficit reduces ALL production by 50% - this MUST be fixed FIRST!\n";
                $prompt .= "   Action required: Build solar_plant or fusion_plant IMMEDIATELY if affordable.\n";
                foreach ($energyWarnings as $warning) {
                    $prompt .= "   - {$warning}\n";
                }
                $prompt .= "\n";
            }
            
            // Show other warnings
            if (!empty($otherWarnings)) {
                foreach ($otherWarnings as $warning) {
                    $prompt .= "  - {$warning}\n";
                }
                $prompt .= "\n";
            }
        }
        
        $prompt .= "=== GAME STATE ===\n\n";
        
        // Build affordability section early so we can reference it
        $affordabilitySection = $this->buildAffordabilitySection($gameState);
        
        // PLANETS
        $prompt .= "PLANETS: " . count($gameState['planets'] ?? []) . "\n\n";
        foreach ($gameState['planets'] ?? [] as $planet) {
            $prompt .= "Planet {$planet['planet_id']}: {$planet['name']}\n";
            $coords = $planet['coordinates'];
            $coordStr = is_object($coords) ? "{$coords->galaxy}:{$coords->system}:{$coords->position}" : (string)$coords;
            $prompt .= "  Coordinates: {$coordStr}\n";
            
            // Resources with storage info
            $prompt .= "  Resources:\n";
            $metalPercent = $planet['metal_capacity'] > 0 ? round(($planet['metal_stored'] / $planet['metal_capacity']) * 100, 1) : 0;
            $crystalPercent = $planet['crystal_capacity'] > 0 ? round(($planet['crystal_stored'] / $planet['crystal_capacity']) * 100, 1) : 0;
            $deuteriumPercent = $planet['deuterium_capacity'] > 0 ? round(($planet['deuterium_stored'] / $planet['deuterium_capacity']) * 100, 1) : 0;
            
            $prompt .= "    metal: {$planet['metal_stored']} / {$planet['metal_capacity']} ({$metalPercent}% full)\n";
            $prompt .= "    crystal: {$planet['crystal_stored']} / {$planet['crystal_capacity']} ({$crystalPercent}% full)\n";
            $prompt .= "    deuterium: {$planet['deuterium_stored']} / {$planet['deuterium_capacity']} ({$deuteriumPercent}% full)\n";
            $prompt .= "    energy: {$planet['energy_available']}";
            if ($planet['energy_available'] < 0) {
                $prompt .= " (DEFICIT! Production reduced by 50%)";
            }
            $prompt .= "\n";
            
            // Production
            $prompt .= "  Production (per hour):\n";
            $prompt .= "    metal: {$planet['metal_production']}\n";
            $prompt .= "    crystal: {$planet['crystal_production']}\n";
            $prompt .= "    deuterium: {$planet['deuterium_production']}\n";
            
            // Queue Status with details
            $prompt .= "  Queues:\n";
            $prompt .= "    build_queue_busy: " . ($planet['build_queue_busy'] ? 'true' : 'false');
            if ($planet['build_queue_count'] > 0) {
                $prompt .= " ({$planet['build_queue_count']} items in queue)";
            }
            $prompt .= "\n";
            $prompt .= "    unit_queue_busy: " . ($planet['unit_queue_busy'] ? 'true' : 'false') . "\n";
            
            // Check for shipyard
            $hasShipyard = false;
            $shipyardLevel = 0;
            foreach ($planet['buildings'] ?? [] as $building) {
                if ($building['type'] === 'shipyard' && $building['level'] > 0) {
                    $hasShipyard = true;
                    $shipyardLevel = $building['level'];
                    break;
                }
            }
            $prompt .= "    shipyard_available: " . ($hasShipyard ? "true (level {$shipyardLevel})" : "false") . "\n";
            
            // Check for robotics factory (affects build speed)
            $roboticsLevel = 0;
            foreach ($planet['buildings'] ?? [] as $building) {
                if ($building['type'] === 'robotics_factory') {
                    $roboticsLevel = $building['level'];
                    break;
                }
            }
            if ($roboticsLevel > 0) {
                $prompt .= "    robotics_factory_level: {$roboticsLevel} (faster building)\n";
            }
            
            // Check for nanite factory (much faster building)
            $naniteLevel = 0;
            foreach ($planet['buildings'] ?? [] as $building) {
                if ($building['type'] === 'nanite_factory') {
                    $naniteLevel = $building['level'];
                    break;
                }
            }
            if ($naniteLevel > 0) {
                $prompt .= "    nanite_factory_level: {$naniteLevel} (very fast building)\n";
            }
            
            // Buildings
            $prompt .= "  Buildings:\n";
            if (!empty($planet['buildings'])) {
                foreach ($planet['buildings'] as $b) {
                    $prompt .= "    {$b['type']}: {$b['level']}\n";
                }
            } else {
                $prompt .= "    (none)\n";
            }
            
            // Defenses
            if (!empty($planet['defenses'])) {
                $prompt .= "  Defenses:\n";
                foreach ($planet['defenses'] as $d) {
                    $prompt .= "    {$d['type']}: {$d['count']}\n";
                }
            }
            
            // Ships
            if (!empty($planet['ships'])) {
                $prompt .= "  Ships:\n";
                foreach ($planet['ships'] as $s) {
                    $prompt .= "    {$s['type']}: {$s['count']}\n";
                }
            }
            
            $prompt .= "\n";
        }
        
        // RESEARCH
        $prompt .= "RESEARCH:\n";
        $prompt .= "  research_queue_busy: " . ($gameState['research']['research_queue_busy'] ? 'true' : 'false') . "\n";
        
        // Check research lab
        $hasResearchLab = false;
        $maxResearchLabLevel = 0;
        foreach ($gameState['planets'] ?? [] as $planet) {
            foreach ($planet['buildings'] ?? [] as $building) {
                if ($building['type'] === 'research_lab' && $building['level'] > 0) {
                    $hasResearchLab = true;
                    $maxResearchLabLevel = max($maxResearchLabLevel, $building['level']);
                }
            }
        }
        $prompt .= "  research_lab_available: " . ($hasResearchLab ? "true (level {$maxResearchLabLevel})" : "false") . "\n";
        
        $prompt .= "  Technologies:\n";
        if (!empty($gameState['research']['technologies'])) {
            foreach ($gameState['research']['technologies'] as $tech) {
                $prompt .= "    {$tech['tech_type']}: {$tech['current_level']}\n";
            }
        } else {
            $prompt .= "    (none)\n";
        }
        $prompt .= "\n";
        
        // FLEET
        $prompt .= "FLEET (empire-wide):\n";
        $prompt .= "  fleet_slots: {$gameState['fleet']['fleet_slots_used']} / {$gameState['fleet']['fleet_slots_max']}\n";
        $prompt .= "  expedition_slots: {$gameState['fleet']['expedition_slots_used']} / {$gameState['fleet']['expedition_slots_max']}\n";
        
        if (!empty($gameState['fleet']['composition'])) {
            $prompt .= "  Ships on planets:\n";
            foreach ($gameState['fleet']['composition'] as $shipType => $count) {
                $prompt .= "    {$shipType}: {$count}\n";
            }
        } else {
            $prompt .= "  Ships on planets: (none)\n";
        }
        
        if (!empty($gameState['fleet']['active_missions'])) {
            $prompt .= "  Active missions:\n";
            foreach ($gameState['fleet']['active_missions'] as $mission) {
                $prompt .= "    mission_type_{$mission['mission_type']}: from planet {$mission['from_planet']} to {$mission['to_planet']}\n";
                if ($mission['arrival_time']) {
                    $prompt .= "      arrival: {$mission['arrival_time']}\n";
                }
                if ($mission['return_time']) {
                    $prompt .= "      return: {$mission['return_time']}\n";
                }
            }
        }
        $prompt .= "\n";
        
        // THREATS
        if (!empty($gameState['threats']['threats'])) {
            $prompt .= "THREATS:\n";
            $prompt .= "  incoming_attacks: {$gameState['threats']['incoming_attacks']}\n";
            foreach ($gameState['threats']['threats'] as $threat) {
                $prompt .= "  - {$threat['type']} at {$threat['arrival_time']}\n";
            }
            $prompt .= "\n";
        }
        
        // AFFORDABILITY STATUS
        $prompt .= $affordabilitySection;
        
        // LAST TURN
        if (!empty($lastTurnSummary)) {
            $prompt .= "LAST TURN:\n";
            if (isset($lastTurnSummary['overall_strategy'])) {
                $prompt .= "  strategy: {$lastTurnSummary['overall_strategy']}\n";
            }
            if (!empty($lastTurnSummary['actions'])) {
                $prompt .= "  actions:\n";
                foreach ($lastTurnSummary['actions'] as $action) {
                    $prompt .= "    - {$action['action_type']} → {$action['target']} = {$action['result']}\n";
                }
            }
            $prompt .= "\n";
        }
        
        $prompt .= "=== YOUR TASK ===\n";
        $prompt .= "Analyze the game state above and decide which actions to take this turn.\n";
        $prompt .= "Return valid JSON with your decision.\n";
        
        return $prompt;
    }

    /**
     * Call AI API with retry logic
     * Routes to appropriate provider (OpenAI or Gemini) based on URL
     */
    protected function callAPI(int $botId, string $apiUrl, string $apiKey, string $model, array $messages): array
    {
        // Detect provider type from URL
        $provider = $this->detectProvider($apiUrl);
        
        // Route to appropriate API handler
        if ($provider === 'gemini') {
            return $this->callGeminiAPI($botId, $apiUrl, $apiKey, $model, $messages);
        }
        
        // Default to OpenAI-compatible API
        return $this->callOpenAIAPI($botId, $apiUrl, $apiKey, $model, $messages);
    }

    /**
     * Call OpenAI-compatible API
     * Handles OpenAI, Groq, and other OpenAI-compatible providers
     */
    protected function callOpenAIAPI(int $botId, string $apiUrl, string $apiKey, string $model, array $messages): array
    {
        $timeout = config('ogame.bots.ai.timeout_seconds', 30);
        
        try {
            $response = Http::timeout($timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($this->buildApiEndpoint($apiUrl), [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => 0.7,
                    'max_tokens' => 20000,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                // Validate OpenAI-compatible response structure
                if (!isset($data['choices']) || !is_array($data['choices']) || empty($data['choices'])) {
                    Log::error("AI API returned invalid structure", [
                        'response' => $data,
                        'missing' => 'choices array',
                    ]);
                    return ['success' => false, 'error' => 'Invalid API response structure: missing choices array'];
                }
                
                if (!isset($data['choices'][0]['message']['content'])) {
                    Log::error("AI API returned invalid structure", [
                        'response' => $data,
                        'missing' => 'message.content',
                    ]);
                    return ['success' => false, 'error' => 'Invalid API response structure: missing message content'];
                }
                
                // Extract token usage details
                $promptTokens = $data['usage']['prompt_tokens'] ?? 0;
                $completionTokens = $data['usage']['completion_tokens'] ?? 0;
                $totalTokens = $data['usage']['total_tokens'] ?? ($promptTokens + $completionTokens);
                
                $cost = $this->calculateCost($model, $totalTokens);
                $content = $data['choices'][0]['message']['content'];
                
                // Write to separate file for easy viewing (no Laravel log)
                $this->writeResponseToFile($botId, $model, $content, $totalTokens, $cost);
                
                return [
                    'success' => true,
                    'data' => $data,
                    'tokens' => $totalTokens,
                    'cost' => $cost,
                ];
            }

            // Parse error response if available
            $errorBody = $response->json();
            $errorMessage = 'API returned ' . $response->status();
            
            // Try to extract OpenAI-style error message
            if (isset($errorBody['error']['message'])) {
                $errorMessage .= ': ' . $errorBody['error']['message'];
            } elseif (isset($errorBody['error'])) {
                $errorMessage .= ': ' . (is_string($errorBody['error']) ? $errorBody['error'] : json_encode($errorBody['error']));
            } elseif (isset($errorBody['message'])) {
                $errorMessage .= ': ' . $errorBody['message'];
            }
            
            Log::warning("AI API failed", [
                'status' => $response->status(),
                'error_message' => $errorMessage,
                'body' => $response->body(),
            ]);
            
            return [
                'success' => false,
                'error' => $errorMessage,
                'raw_response' => $response->body(),
            ];
        } catch (Exception $e) {
            Log::error("AI API exception", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Call Gemini API with transformed messages
     * 
     * @param int $botId Bot ID for logging
     * @param string $apiKey Gemini API key
     * @param string $model Gemini model name (e.g., "gemini-1.5-flash")
     * @param array $messages OpenAI-style messages to transform and send
     * @return array Normalized response with 'success', 'data', 'tokens', and 'cost' keys
     */
    protected function callGeminiAPI(int $botId, string $apiUrl, string $apiKey, string $model, array $messages): array
    {
        $timeout = config('ogame.bots.ai.timeout_seconds', 30);
        
        try {
            // Transform messages to Gemini format
            $geminiRequest = $this->transformMessagesToGemini($messages);
            
            // Build Gemini endpoint URL with API key
            $endpoint = $this->buildGeminiEndpoint($apiUrl, $model, $apiKey);
            
            // Make HTTP request to Gemini API
            // Note: Gemini uses API key in URL, not Authorization header
            $response = Http::timeout($timeout)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($endpoint, $geminiRequest);

            if ($response->successful()) {
                $data = $response->json();
                
                // Parse and normalize Gemini response
                $result = $this->parseGeminiResponse($data, $model);
                
                if ($result['success']) {
                    // Write to separate file for easy viewing (no Laravel log)
                    $content = $result['data']['choices'][0]['message']['content'];
                    $this->writeResponseToFile($botId, $model, $content, $result['tokens'], $result['cost']);
                }
                
                return $result;
            }

            // Parse error response if available
            $errorBody = $response->json();
            $errorMessage = 'Gemini API returned ' . $response->status();
            
            // Try to extract Gemini-style error message
            if (isset($errorBody['error']['message'])) {
                $errorMessage .= ': ' . $errorBody['error']['message'];
            } elseif (isset($errorBody['error'])) {
                $errorMessage .= ': ' . (is_string($errorBody['error']) ? $errorBody['error'] : json_encode($errorBody['error']));
            } elseif (isset($errorBody['message'])) {
                $errorMessage .= ': ' . $errorBody['message'];
            }
            
            Log::warning("Gemini API failed", [
                'status' => $response->status(),
                'error_message' => $errorMessage,
                'body' => $response->body(),
            ]);
            
            return [
                'success' => false,
                'error' => $errorMessage,
                'raw_response' => $response->body(),
            ];
        } catch (Exception $e) {
            Log::error("Gemini API exception", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Parse Gemini API response and normalize to OpenAI format
     * 
     * @param array $geminiResponse The raw response from Gemini API
     * @return array Normalized response with 'success', 'data', 'tokens', and 'cost' keys
     */
    protected function parseGeminiResponse(array $geminiResponse, string $model): array
    {
        // Validate response structure - check for required fields
        if (!isset($geminiResponse['candidates']) || !is_array($geminiResponse['candidates'])) {
            Log::error("Gemini API returned invalid structure", [
                'response' => $geminiResponse,
                'missing' => 'candidates array',
            ]);
            return ['success' => false, 'error' => 'Invalid Gemini response structure: missing candidates array'];
        }
        
        if (empty($geminiResponse['candidates'])) {
            Log::error("Gemini API returned empty candidates", [
                'response' => $geminiResponse,
            ]);
            return ['success' => false, 'error' => 'Invalid Gemini response structure: empty candidates array'];
        }
        
        // Extract text content from first candidate
        $candidate = $geminiResponse['candidates'][0];
        
        // Check if response was truncated due to MAX_TOKENS
        $finishReason = $candidate['finishReason'] ?? null;
        if ($finishReason === 'MAX_TOKENS') {
            Log::warning("Gemini API reached MAX_TOKENS limit - response may be incomplete", [
                'candidate' => $candidate,
                'raw_response' => json_encode($geminiResponse),
            ]);
            return [
                'success' => false,
                'error' => 'Gemini API reached MAX_TOKENS limit - try increasing max_tokens or reducing prompt size',
                'raw_response' => json_encode($geminiResponse),
            ];
        }
        
        // Check if parts exists and has content
        if (!isset($candidate['content']['parts']) || !is_array($candidate['content']['parts']) || empty($candidate['content']['parts'])) {
            Log::error("Gemini API returned invalid candidate structure", [
                'candidate' => $candidate,
                'missing' => 'content.parts array',
                'finishReason' => $finishReason,
            ]);
            return ['success' => false, 'error' => 'Invalid Gemini response structure: missing content.parts array'];
        }
        
        $text = $candidate['content']['parts'][0]['text'] ?? null;
        
        if ($text === null || trim($text) === '') {
            Log::error("Gemini API returned no text content", [
                'parts' => $candidate['content']['parts'],
                'finishReason' => $finishReason,
            ]);
            return ['success' => false, 'error' => 'Invalid Gemini response structure: missing text in parts'];
        }
        
        // Extract token usage from usageMetadata
        if (!isset($geminiResponse['usageMetadata'])) {
            Log::error("Gemini API returned no usage metadata", [
                'response' => $geminiResponse,
            ]);
            return ['success' => false, 'error' => 'Invalid Gemini response structure: missing usageMetadata'];
        }
        
        $promptTokens = $geminiResponse['usageMetadata']['promptTokenCount'] ?? 0;
        $completionTokens = $geminiResponse['usageMetadata']['candidatesTokenCount'] ?? 0;
        $totalTokens = $geminiResponse['usageMetadata']['totalTokenCount'] ?? ($promptTokens + $completionTokens);
        
        // Calculate cost using Gemini pricing
        $cost = $this->calculateCost($model, $totalTokens);
        
        // Normalize to OpenAI format
        $normalizedResponse = [
            'choices' => [
                [
                    'message' => [
                        'content' => $text,
                    ],
                ],
            ],
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
            ],
        ];
        
        return [
            'success' => true,
            'data' => $normalizedResponse,
            'tokens' => $totalTokens,
            'cost' => $cost,
        ];
    }

    /**
     * Build Gemini API endpoint URL
     * Constructs URL in format: {baseUrl}/v1beta/models/{model}:generateContent?key={apiKey}
     * 
     * @param string $baseUrl The base URL from bot configuration
     * @param string $model The model name (e.g., "gemini-1.5-flash")
     * @param string $apiKey The API key for authentication
     * @return string Complete Gemini API endpoint URL with API key
     */
    protected function buildGeminiEndpoint(string $baseUrl, string $model, string $apiKey): string
    {
        // Remove trailing slash from base URL
        $baseUrl = rtrim($baseUrl, '/');
        
        // Construct Gemini endpoint: {baseUrl}/v1beta/models/{model}:generateContent
        $endpoint = "{$baseUrl}/v1beta/models/{$model}:generateContent";
        
        // Append API key as query parameter
        $endpoint .= "?key={$apiKey}";
        
        return $endpoint;
    }

    /**
     * Build API endpoint URL
     * Handles both base URLs and full endpoint URLs
     */
    protected function buildApiEndpoint(string $apiUrl): string
    {
        // Remove trailing slash
        $apiUrl = rtrim($apiUrl, '/');
        
        // If URL already ends with /chat/completions, use as-is
        if (str_ends_with($apiUrl, '/chat/completions')) {
            return $apiUrl;
        }
        
        // Otherwise append /chat/completions
        return $apiUrl . '/chat/completions';
    }

    protected function estimateTokens(array $messages): int
    {
        $text = json_encode($messages);
        return (int)ceil(strlen($text) / 4);
    }

    protected function calculateCost(string $model, int $tokens): float
    {
        $rates = [
            // OpenAI models
            'gpt-4o-mini' => 0.15,
            
            // Groq models
            'llama3-70b-8192' => 0.05,
            'llama2' => 0,
            
            // Gemini models (pricing per 1M tokens, averaged input/output)
            'gemini-1.5-flash' => 0.075,      // $0.075/$0.30 per 1M tokens (avg)
            'gemini-1.5-pro' => 1.25,         // $1.25/$5.00 per 1M tokens (avg)
            'gemini-1.0-pro' => 0.50,         // $0.50/$1.50 per 1M tokens (avg)
        ];
        
        // Use default rate for unknown models
        $rate = $rates[$model] ?? 0.10;
        return ($tokens / 1_000_000) * $rate;
    }

    /**
     * Log switch to backup provider
     */
    private function writeBackupProviderSwitch(int $botId, string $backupModel, string $primaryError): void
    {
        try {
            $date = date('Y-m-d');
            $timestamp = date('Y-m-d H:i:s');
            $logDir = storage_path("logs/bots/bot-{$botId}");
            
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            
            $logFile = "{$logDir}/{$date}.log";
            
            $content = str_repeat('=', 100) . "\n";
            $content .= "🔄 SWITCHING TO BACKUP PROVIDER [{$timestamp}]\n";
            $content .= str_repeat('=', 100) . "\n";
            $content .= "Bot ID: {$botId}\n";
            $content .= "Backup Model: {$backupModel}\n";
            $content .= "Reason: Primary provider failed - {$primaryError}\n\n";
            
            file_put_contents($logFile, $content, FILE_APPEND);
        } catch (\Exception $e) {
            // Don't fail if logging fails
            Log::warning("Failed to write backup provider switch log: " . $e->getMessage());
        }
    }

    /**
     * Log quota exceeded to bot file
     */
    private function logQuotaExceeded(int $botId, string $message): void
    {
        try {
            $date = date('Y-m-d');
            $timestamp = date('Y-m-d H:i:s');
            $logDir = storage_path("logs/bots/bot-{$botId}");
            
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            
            $logFile = "{$logDir}/{$date}.log";
            
            $content = str_repeat('=', 100) . "\n";
            $content .= "⚠️  QUOTA EXCEEDED [{$timestamp}]\n";
            $content .= str_repeat('=', 100) . "\n";
            $content .= "Bot ID: {$botId}\n";
            $content .= "Message: {$message}\n";
            $content .= "\nAPI call was skipped due to quota limit.\n\n";
            
            file_put_contents($logFile, $content, FILE_APPEND);
        } catch (\Exception $e) {
            // Don't fail if logging fails
            Log::warning("Failed to write quota exceeded log: " . $e->getMessage());
        }
    }

    /**
     * Log error response to bot file (when API call fails)
     */
    private function writeErrorResponseToFile(int $botId, string $model, string $errorMessage, string $rawResponse): void
    {
        try {
            $date = date('Y-m-d');
            $timestamp = date('Y-m-d H:i:s');
            $logDir = storage_path("logs/bots/bot-{$botId}");
            
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            
            $logFile = "{$logDir}/{$date}.log";
            
            $content = str_repeat('=', 100) . "\n";
            $content .= "❌ API ERROR RESPONSE [{$timestamp}]\n";
            $content .= str_repeat('=', 100) . "\n";
            $content .= "Model: {$model}\n";
            $content .= "Bot ID: {$botId}\n";
            $content .= "Error: {$errorMessage}\n\n";
            
            // NOTE: Raw response logging disabled to reduce log file size     
            $content .= str_repeat('-', 100) . "\n";
            $content .= "RAW RESPONSE:\n";
            $content .= str_repeat('-', 100) . "\n";
            
            // Try to pretty-print JSON response
            $decoded = json_decode($rawResponse, true);
            if ($decoded) {
                $content .= json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            } else {
                $content .= $rawResponse . "\n";
            }            
            
            $content .= "\n\n";
            
            file_put_contents($logFile, $content, FILE_APPEND);
        } catch (\Exception $e) {
            // Don't fail if logging fails
            Log::warning("Failed to write error response to file: " . $e->getMessage());
        }
    }

    /**
     * Write prompt to log file
     */
    private function writePromptToFile(int $botId, string $model, array $messages): void
    {
        try {
            $date = date('Y-m-d');
            $timestamp = date('Y-m-d H:i:s');
            $logDir = storage_path("logs/bots/bot-{$botId}");
            
            // Create directory if it doesn't exist
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            
            $logFile = "{$logDir}/{$date}.log";
            
            $content = str_repeat('=', 100) . "\n";
            $content .= "📤 API REQUEST [{$timestamp}]\n";
            $content .= str_repeat('=', 100) . "\n";
            $content .= "Model: {$model}\n";
            $content .= "Bot ID: {$botId}\n\n";
            
            foreach ($messages as $msg) {
                $role = strtoupper($msg['role'] ?? 'unknown');
                $msgContent = $msg['content'] ?? '';
                
                $content .= str_repeat('-', 100) . "\n";
                $content .= "[{$role}]\n";
                $content .= str_repeat('-', 100) . "\n";
                $content .= $msgContent . "\n\n";
            }
            
            $content .= "\n";
            
            file_put_contents($logFile, $content, FILE_APPEND);
        } catch (\Exception $e) {
            // Don't fail if logging fails
            Log::warning("Failed to write prompt to file: " . $e->getMessage());
        }
    }
    
    /**
     * Write response to log file (same file as prompt)
     */
    private function writeResponseToFile(int $botId, string $model, string $response, int $tokens, float $cost): void
    {
        try {
            $date = date('Y-m-d');
            $timestamp = date('Y-m-d H:i:s');
            $logDir = storage_path("logs/bots/bot-{$botId}");
            
            // Create directory if it doesn't exist
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            
            $logFile = "{$logDir}/{$date}.log";
            
            $content = str_repeat('=', 100) . "\n";
            $content .= "📥 API RESPONSE [{$timestamp}]\n";
            $content .= str_repeat('=', 100) . "\n";
            $content .= "Model: {$model}\n";
            $content .= "Bot ID: {$botId}\n";
            $content .= "Tokens: {$tokens}\n";
            $content .= "Cost: $" . number_format($cost, 6) . "\n\n";
            
            $content .= str_repeat('-', 100) . "\n";
            $content .= "RAW RESPONSE:\n";
            $content .= str_repeat('-', 100) . "\n";
            
            // Preprocess response to remove markdown code blocks before parsing
            $cleanedResponse = $this->preprocessAIResponse($response);
            
            // Try to pretty-print JSON
            $decoded = json_decode($cleanedResponse, true);
            if ($decoded) {
                $content .= json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                
                // Add analysis
                $content .= "\n";
                $content .= str_repeat('-', 100) . "\n";
                $content .= "ANALYSIS:\n";
                $content .= str_repeat('-', 100) . "\n";
                
                if (isset($decoded['actions'])) {
                    $actionCount = count($decoded['actions']);
                    $content .= "Actions: {$actionCount}\n";
                    
                    if ($actionCount > 0) {
                        foreach ($decoded['actions'] as $action) {
                            $content .= "  - " . ($action['action_type'] ?? 'unknown') . " → " . ($action['target'] ?? 'N/A') . "\n";
                        }
                    } else {
                        $content .= "  (Bot decided to WAIT)\n";
                    }
                }
                
                if (isset($decoded['overall_strategy'])) {
                    $content .= "Strategy: " . $decoded['overall_strategy'] . "\n";
                }
            } else {
                $content .= $response . "\n";
                $content .= "\n⚠️  Response is not valid JSON\n";
            }
            
            $content .= "\n\n";
            
            file_put_contents($logFile, $content, FILE_APPEND);
        } catch (\Exception $e) {
            // Don't fail if logging fails
            Log::warning("Failed to write response to file: " . $e->getMessage());
        }
    }

    /**
     * Preprocess AI response to extract clean JSON
     * Removes <think> tags, markdown code blocks, and other non-JSON content
     *
     * @param string $rawResponse
     * @return string Clean JSON string
     */
    protected function preprocessAIResponse(string $rawResponse): string
    {
        // Remove <think> tags and their content (common in reasoning models)
        $cleaned = preg_replace('/<think>.*?<\/think>/s', '', $rawResponse);
        
        // Remove markdown code blocks - handle both ```json and ``` variants
        // This handles cases like: ```json{"actions": []}```
        $cleaned = preg_replace('/```(?:json)?\s*(.*?)\s*```/s', '$1', $cleaned);
        
        // Trim whitespace
        $cleaned = trim($cleaned);
        
        // If the response starts with text before JSON, try to extract just the JSON
        if (!str_starts_with($cleaned, '{') && !str_starts_with($cleaned, '[')) {
            // Find the first { or [ and extract from there
            if (preg_match('/(\{.*\}|\[.*\])/s', $cleaned, $matches)) {
                $cleaned = $matches[1];
            }
        }
        
        return $cleaned;
    }
}

