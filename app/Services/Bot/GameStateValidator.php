<?php

namespace OGame\Services\Bot;

use Illuminate\Support\Facades\Log;

/**
 * Game State Validator
 *
 * Validates game state data for logical consistency before sending to AI.
 * Detects and auto-corrects common inconsistencies to improve AI decision quality.
 */
class GameStateValidator
{
    /**
     * Get bot log channel
     */
    protected static function botLog()
    {
        return Log::channel('bot');
    }

    /**
     * Get validation warnings without logging (for use in AI prompt)
     *
     * @param array $gameState The game state to validate
     * @return array Array of warning messages
     */
    public static function getWarnings(array $gameState): array
    {
        $warnings = [];

        // Validate each planet
        foreach ($gameState['planets'] ?? [] as $index => $planet) {
            $planetWarnings = self::validatePlanet($planet, $gameState['planets'][$index]);
            $warnings = array_merge($warnings, $planetWarnings);
        }

        // Validate research state
        $researchWarnings = self::validateResearch($gameState['research'] ?? []);
        $warnings = array_merge($warnings, $researchWarnings);

        return $warnings;
    }

    /**
     * Validate game state for logical consistency
     *
     * @param array $gameState The game state to validate
     * @return array The validated (and potentially corrected) game state
     */
    public static function validate(array $gameState): array
    {
        $warnings = self::getWarnings($gameState);

        // Log all warnings if any were found
        if (!empty($warnings)) {
            $botId = $gameState['bot_id'] ?? 'unknown';

            // Log to bot-specific log file
            self::logToBotFile($botId, $warnings);

            // Also log to bot log channel
            self::botLog()->warning("Game state validation detected inconsistencies", [
                'bot_id' => $botId,
                'warning_count' => count($warnings),
            ]);
        }

        return $gameState;
    }

    /**
     * Write validation warnings to bot-specific log file
     *
     * @param mixed $botId The bot identifier
     * @param array $warnings Array of warning messages
     * @return void
     */
    protected static function logToBotFile($botId, array $warnings): void
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

            // Add searchable tag line
            $warningCount = count($warnings);
            $searchableLine = "[VALIDATION_WARNINGS] [{$timestamp}] Bot:{$botId} Count:{$warningCount}";

            $content = str_repeat('=', 100) . "\n";
            $content .= "{$searchableLine}\n";
            $content .= str_repeat('=', 100) . "\n";
            $content .= "⚠️  GAME STATE VALIDATION WARNINGS [{$timestamp}]\n";
            $content .= str_repeat('=', 100) . "\n";
            $content .= "Bot ID: {$botId}\n";
            $content .= "Warnings: {$warningCount}\n\n";

            foreach ($warnings as $index => $warning) {
                $content .= ($index + 1) . ". {$warning}\n";
            }

            $content .= "\n";

            file_put_contents($logFile, $content, FILE_APPEND);
        } catch (\Exception $e) {
            // Don't fail if logging fails - fall back to Laravel log
            self::botLog()->warning("Failed to write validation warnings to bot log file: " . $e->getMessage());
        }
    }

    /**
     * Validate a single planet's data
     *
     * @param array $planet The planet data to validate
     * @param array &$planetRef Reference to the planet in the game state (for auto-correction)
     * @return array Array of warning messages
     */
    protected static function validatePlanet(array $planet, array &$planetRef): array
    {
        $warnings = [];
        $planetId = $planet['planet_id'] ?? 'unknown';

        // Check production vs building levels
        $warnings = array_merge($warnings, self::validateProduction($planet, $planetId));

        // Check queue consistency
        $warnings = array_merge($warnings, self::validateQueues($planet, $planetRef, $planetId));

        // Check energy deficit vs production reduction
        $warnings = array_merge($warnings, self::validateEnergy($planet, $planetId));

        // Check storage capacity consistency
        $warnings = array_merge($warnings, self::validateStorage($planet, $planetId));

        return $warnings;
    }

    /**
     * Validate production rates match building levels
     *
     * @param array $planet The planet data
     * @param mixed $planetId The planet identifier
     * @return array Array of warning messages
     */
    protected static function validateProduction(array $planet, $planetId): array
    {
        $warnings = [];

        // Get building levels
        $metalMineLevel = self::getBuildingLevel($planet, 'metal_mine');
        $crystalMineLevel = self::getBuildingLevel($planet, 'crystal_mine');
        $deuteriumSynthLevel = self::getBuildingLevel($planet, 'deuterium_synthesizer');

        // Check metal production
        if ($planet['metal_production'] > 0) {
            if ($metalMineLevel === 0 && $planet['metal_production'] > 30) {
                $warnings[] = "Planet {$planetId}: High metal production ({$planet['metal_production']}/h) but metal_mine is level 0";
            }
        }

        // Check crystal production
        if ($planet['crystal_production'] > 0) {
            if ($crystalMineLevel === 0 && $planet['crystal_production'] > 15) {
                $warnings[] = "Planet {$planetId}: High crystal production ({$planet['crystal_production']}/h) but crystal_mine is level 0";
            }
        }

        // Check deuterium production
        if ($planet['deuterium_production'] > 0) {
            if ($deuteriumSynthLevel === 0 && $planet['deuterium_production'] > 0) {
                $warnings[] = "Planet {$planetId}: Deuterium production ({$planet['deuterium_production']}/h) but deuterium_synthesizer is level 0";
            }
        }

        // Check for negative production (should only be deuterium in rare cases)
        if ($planet['metal_production'] < 0) {
            $warnings[] = "Planet {$planetId}: Negative metal production ({$planet['metal_production']}/h) - this is unusual";
        }
        if ($planet['crystal_production'] < 0) {
            $warnings[] = "Planet {$planetId}: Negative crystal production ({$planet['crystal_production']}/h) - this is unusual";
        }

        return $warnings;
    }

    /**
     * Validate queue consistency
     *
     * @param array $planet The planet data
     * @param array &$planetRef Reference to the planet in the game state (for auto-correction)
     * @param mixed $planetId The planet identifier
     * @return array Array of warning messages
     */
    protected static function validateQueues(array $planet, array &$planetRef, $planetId): array
    {
        $warnings = [];

        // Check build queue consistency
        if ($planet['build_queue_busy'] && $planet['build_queue_count'] === 0) {
            $warnings[] = "Planet {$planetId}: build_queue_busy=true but no items in queue - AUTO-CORRECTING to false";
            // Auto-fix: Set busy flag to false
            $planetRef['build_queue_busy'] = false;
        }

        if (!$planet['build_queue_busy'] && $planet['build_queue_count'] > 0) {
            $warnings[] = "Planet {$planetId}: build_queue_busy=false but {$planet['build_queue_count']} items in queue - AUTO-CORRECTING to true";
            // Auto-fix: Set busy flag to true
            $planetRef['build_queue_busy'] = true;
        }

        // Check unit queue consistency
        // Note: unit_queue_busy is a boolean, we don't have count for unit queue in current structure
        // If we had unit_queue_count, we would validate it here

        return $warnings;
    }

    /**
     * Validate energy deficit and production reduction
     *
     * @param array $planet The planet data
     * @param mixed $planetId The planet identifier
     * @return array Array of warning messages
     */
    protected static function validateEnergy(array $planet, $planetId): array
    {
        $warnings = [];

        // Check if energy is negative
        if ($planet['energy_available'] < 0) {
            // When energy is negative, production should be reduced
            // In OGame, production is typically reduced to 50% or less

            // Calculate expected maximum production based on building levels
            $metalMineLevel = self::getBuildingLevel($planet, 'metal_mine');
            $crystalMineLevel = self::getBuildingLevel($planet, 'crystal_mine');
            $deuteriumSynthLevel = self::getBuildingLevel($planet, 'deuterium_synthesizer');

            // Rough estimate: each mine level produces approximately level * 30 per hour at 100%
            // This is a simplified check - actual production formulas are more complex
            $estimatedFullMetalProduction = $metalMineLevel * 30;
            $estimatedFullCrystalProduction = $crystalMineLevel * 20;
            $estimatedFullDeuteriumProduction = $deuteriumSynthLevel * 10;

            // Check if production seems too high for energy deficit
            if ($metalMineLevel > 0 && $planet['metal_production'] > $estimatedFullMetalProduction * 0.6) {
                $warnings[] = "Planet {$planetId}: Energy deficit ({$planet['energy_available']}) but metal production ({$planet['metal_production']}/h) seems high for level {$metalMineLevel} mine";
            }

            if ($crystalMineLevel > 0 && $planet['crystal_production'] > $estimatedFullCrystalProduction * 0.6) {
                $warnings[] = "Planet {$planetId}: Energy deficit ({$planet['energy_available']}) but crystal production ({$planet['crystal_production']}/h) seems high for level {$crystalMineLevel} mine";
            }

            if ($deuteriumSynthLevel > 0 && $planet['deuterium_production'] > $estimatedFullDeuteriumProduction * 0.6) {
                $warnings[] = "Planet {$planetId}: Energy deficit ({$planet['energy_available']}) but deuterium production ({$planet['deuterium_production']}/h) seems high for level {$deuteriumSynthLevel} synthesizer";
            }
        }

        return $warnings;
    }

    /**
     * Validate storage capacity
     *
     * @param array $planet The planet data
     * @param mixed $planetId The planet identifier
     * @return array Array of warning messages
     */
    protected static function validateStorage(array $planet, $planetId): array
    {
        $warnings = [];

        // Check if stored resources exceed capacity
        if ($planet['metal_stored'] > $planet['metal_capacity']) {
            $warnings[] = "Planet {$planetId}: Metal stored ({$planet['metal_stored']}) exceeds capacity ({$planet['metal_capacity']})";
        }

        if ($planet['crystal_stored'] > $planet['crystal_capacity']) {
            $warnings[] = "Planet {$planetId}: Crystal stored ({$planet['crystal_stored']}) exceeds capacity ({$planet['crystal_capacity']})";
        }

        if ($planet['deuterium_stored'] > $planet['deuterium_capacity']) {
            $warnings[] = "Planet {$planetId}: Deuterium stored ({$planet['deuterium_stored']}) exceeds capacity ({$planet['deuterium_capacity']})";
        }

        // Check for zero or negative capacity (should not happen)
        if ($planet['metal_capacity'] <= 0) {
            $warnings[] = "Planet {$planetId}: Metal capacity is {$planet['metal_capacity']} - should be positive";
        }

        if ($planet['crystal_capacity'] <= 0) {
            $warnings[] = "Planet {$planetId}: Crystal capacity is {$planet['crystal_capacity']} - should be positive";
        }

        if ($planet['deuterium_capacity'] <= 0) {
            $warnings[] = "Planet {$planetId}: Deuterium capacity is {$planet['deuterium_capacity']} - should be positive";
        }

        return $warnings;
    }

    /**
     * Validate research state
     *
     * @param array $research The research data
     * @return array Array of warning messages
     */
    protected static function validateResearch(array $research): array
    {
        $warnings = [];

        // Check research queue consistency
        // Note: We don't have research_queue_count in current structure
        // If research_queue_busy is true, there should be an active research

        // Check for negative research levels (should not happen)
        foreach ($research['technologies'] ?? [] as $tech) {
            if ($tech['current_level'] < 0) {
                $warnings[] = "Research {$tech['tech_type']}: Negative level ({$tech['current_level']}) detected";
            }
        }

        return $warnings;
    }

    /**
     * Get building level from planet data
     *
     * @param array $planet The planet data
     * @param string $buildingType The building machine name
     * @return int The building level (0 if not found)
     */
    protected static function getBuildingLevel(array $planet, string $buildingType): int
    {
        foreach ($planet['buildings'] ?? [] as $building) {
            if ($building['type'] === $buildingType) {
                return $building['level'];
            }
        }
        return 0;
    }
}
