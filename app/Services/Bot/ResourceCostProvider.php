<?php

namespace OGame\Services\Bot;

/**
 * Provides resource cost information for buildings, research, and units.
 * This class centralizes cost data for use in bot AI prompts and decision-making.
 */
class ResourceCostProvider
{
    /**
     * Get base costs for all buildings (level 1)
     *
     * @return array<string, array{metal: int, crystal: int, deuterium: int, factor: float}>
     */
    public static function getBuildingCosts(): array
    {
        return [
            // Resource production buildings
            'metal_mine' => ['metal' => 60, 'crystal' => 15, 'deuterium' => 0, 'factor' => 1.5],
            'crystal_mine' => ['metal' => 48, 'crystal' => 24, 'deuterium' => 0, 'factor' => 1.6],
            'deuterium_synthesizer' => ['metal' => 225, 'crystal' => 75, 'deuterium' => 0, 'factor' => 1.5],
            'solar_plant' => ['metal' => 75, 'crystal' => 30, 'deuterium' => 0, 'factor' => 1.5],
            'fusion_plant' => ['metal' => 900, 'crystal' => 360, 'deuterium' => 180, 'factor' => 1.8],
            
            // Storage buildings
            'metal_store' => ['metal' => 1000, 'crystal' => 0, 'deuterium' => 0, 'factor' => 2.0],
            'crystal_store' => ['metal' => 1000, 'crystal' => 500, 'deuterium' => 0, 'factor' => 2.0],
            'deuterium_store' => ['metal' => 1000, 'crystal' => 1000, 'deuterium' => 0, 'factor' => 2.0],
            
            // Station buildings (facilities)
            'robot_factory' => ['metal' => 400, 'crystal' => 120, 'deuterium' => 200, 'factor' => 2.0],
            'shipyard' => ['metal' => 400, 'crystal' => 200, 'deuterium' => 100, 'factor' => 2.0],
            'research_lab' => ['metal' => 200, 'crystal' => 400, 'deuterium' => 200, 'factor' => 2.0],
            'alliance_depot' => ['metal' => 20000, 'crystal' => 40000, 'deuterium' => 0, 'factor' => 2.0],
            'missile_silo' => ['metal' => 20000, 'crystal' => 20000, 'deuterium' => 1000, 'factor' => 2.0],
            'nano_factory' => ['metal' => 1000000, 'crystal' => 500000, 'deuterium' => 100000, 'factor' => 2.0],
            'terraformer' => ['metal' => 50000, 'crystal' => 0, 'deuterium' => 100000, 'factor' => 2.0],
            'space_dock' => ['metal' => 200, 'crystal' => 0, 'deuterium' => 50, 'factor' => 2.0],
            
            // Moon buildings
            'lunar_base' => ['metal' => 20000, 'crystal' => 40000, 'deuterium' => 20000, 'factor' => 2.0],
            'sensor_phalanx' => ['metal' => 20000, 'crystal' => 40000, 'deuterium' => 10000, 'factor' => 2.0],
            'jump_gate' => ['metal' => 2000000, 'crystal' => 4000000, 'deuterium' => 2000000, 'factor' => 2.0],
        ];
    }

    /**
     * Get base costs for research (level 1)
     *
     * @return array<string, array{metal: int, crystal: int, deuterium: int, factor: float}>
     */
    public static function getResearchCosts(): array
    {
        return [
            'energy_technology' => ['metal' => 0, 'crystal' => 800, 'deuterium' => 400, 'factor' => 2.0],
            'laser_technology' => ['metal' => 200, 'crystal' => 100, 'deuterium' => 0, 'factor' => 2.0],
            'ion_technology' => ['metal' => 1000, 'crystal' => 300, 'deuterium' => 100, 'factor' => 2.0],
            'hyperspace_technology' => ['metal' => 0, 'crystal' => 4000, 'deuterium' => 2000, 'factor' => 2.0],
            'plasma_technology' => ['metal' => 2000, 'crystal' => 4000, 'deuterium' => 1000, 'factor' => 2.0],
            'combustion_drive' => ['metal' => 400, 'crystal' => 0, 'deuterium' => 600, 'factor' => 2.0],
            'impulse_drive' => ['metal' => 2000, 'crystal' => 4000, 'deuterium' => 600, 'factor' => 2.0],
            'hyperspace_drive' => ['metal' => 10000, 'crystal' => 20000, 'deuterium' => 6000, 'factor' => 2.0],
            'espionage_technology' => ['metal' => 200, 'crystal' => 1000, 'deuterium' => 200, 'factor' => 2.0],
            'computer_technology' => ['metal' => 0, 'crystal' => 400, 'deuterium' => 600, 'factor' => 2.0],
            'astrophysics' => ['metal' => 4000, 'crystal' => 8000, 'deuterium' => 4000, 'factor' => 1.75],
            'intergalactic_research_network' => ['metal' => 240000, 'crystal' => 400000, 'deuterium' => 160000, 'factor' => 2.0],
            'graviton_technology' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'factor' => 2.0],
            'weapon_technology' => ['metal' => 800, 'crystal' => 200, 'deuterium' => 0, 'factor' => 2.0],
            'shielding_technology' => ['metal' => 200, 'crystal' => 600, 'deuterium' => 0, 'factor' => 2.0],
            'armor_technology' => ['metal' => 1000, 'crystal' => 0, 'deuterium' => 0, 'factor' => 2.0],
        ];
    }

    /**
     * Get costs for units and ships
     *
     * @return array<string, array{metal: int, crystal: int, deuterium: int}>
     */
    public static function getUnitCosts(): array
    {
        return [
            // Civil ships
            'small_cargo' => ['metal' => 2000, 'crystal' => 2000, 'deuterium' => 0],
            'large_cargo' => ['metal' => 6000, 'crystal' => 6000, 'deuterium' => 0],
            'colony_ship' => ['metal' => 10000, 'crystal' => 20000, 'deuterium' => 10000],
            'recycler' => ['metal' => 10000, 'crystal' => 6000, 'deuterium' => 2000],
            'espionage_probe' => ['metal' => 0, 'crystal' => 1000, 'deuterium' => 0],
            'solar_satellite' => ['metal' => 0, 'crystal' => 2000, 'deuterium' => 500],
            
            // Military ships
            'light_fighter' => ['metal' => 3000, 'crystal' => 1000, 'deuterium' => 0],
            'heavy_fighter' => ['metal' => 6000, 'crystal' => 4000, 'deuterium' => 0],
            'cruiser' => ['metal' => 20000, 'crystal' => 7000, 'deuterium' => 2000],
            'battle_ship' => ['metal' => 45000, 'crystal' => 15000, 'deuterium' => 0],
            'battlecruiser' => ['metal' => 30000, 'crystal' => 40000, 'deuterium' => 15000],
            'bomber' => ['metal' => 50000, 'crystal' => 25000, 'deuterium' => 15000],
            'destroyer' => ['metal' => 60000, 'crystal' => 50000, 'deuterium' => 15000],
            'deathstar' => ['metal' => 5000000, 'crystal' => 4000000, 'deuterium' => 1000000],
            
            // Defense
            'rocket_launcher' => ['metal' => 2000, 'crystal' => 0, 'deuterium' => 0],
            'light_laser' => ['metal' => 1500, 'crystal' => 500, 'deuterium' => 0],
            'heavy_laser' => ['metal' => 6000, 'crystal' => 2000, 'deuterium' => 0],
            'gauss_cannon' => ['metal' => 20000, 'crystal' => 15000, 'deuterium' => 2000],
            'ion_cannon' => ['metal' => 2000, 'crystal' => 6000, 'deuterium' => 0],
            'plasma_turret' => ['metal' => 50000, 'crystal' => 50000, 'deuterium' => 30000],
            'small_shield_dome' => ['metal' => 10000, 'crystal' => 10000, 'deuterium' => 0],
            'large_shield_dome' => ['metal' => 50000, 'crystal' => 50000, 'deuterium' => 0],
            'anti_ballistic_missile' => ['metal' => 8000, 'crystal' => 2000, 'deuterium' => 0],
            'interplanetary_missile' => ['metal' => 12500, 'crystal' => 2500, 'deuterium' => 10000],
        ];
    }

    /**
     * Calculate cost for specific building level using exponential formula
     *
     * @param string $building Building machine name
     * @param int $level Target level to calculate cost for
     * @return array{metal: int, crystal: int, deuterium: int}
     */
    public static function calculateBuildingCost(string $building, int $level): array
    {
        $baseCost = self::getBuildingCosts()[$building] ?? null;
        if (!$baseCost) {
            return ['metal' => 0, 'crystal' => 0, 'deuterium' => 0];
        }

        // OGame formula: cost = baseCost * factor^(level-1)
        $factor = $baseCost['factor'];
        $multiplier = pow($factor, $level - 1);
        
        return [
            'metal' => (int)floor($baseCost['metal'] * $multiplier),
            'crystal' => (int)floor($baseCost['crystal'] * $multiplier),
            'deuterium' => (int)floor($baseCost['deuterium'] * $multiplier),
        ];
    }

    /**
     * Calculate cost for specific research level using exponential formula
     *
     * @param string $research Research machine name
     * @param int $level Target level to calculate cost for
     * @return array{metal: int, crystal: int, deuterium: int}
     */
    public static function calculateResearchCost(string $research, int $level): array
    {
        $baseCost = self::getResearchCosts()[$research] ?? null;
        if (!$baseCost) {
            return ['metal' => 0, 'crystal' => 0, 'deuterium' => 0];
        }

        // OGame formula: cost = baseCost * factor^(level-1)
        $factor = $baseCost['factor'];
        $multiplier = pow($factor, $level - 1);
        
        return [
            'metal' => (int)floor($baseCost['metal'] * $multiplier),
            'crystal' => (int)floor($baseCost['crystal'] * $multiplier),
            'deuterium' => (int)floor($baseCost['deuterium'] * $multiplier),
        ];
    }

    /**
     * Get building prerequisites
     * Returns array of buildings that require other buildings to be built first
     *
     * @return array<string, array<array{building: string, level: int}>>
     */
    public static function getBuildingPrerequisites(): array
    {
        return [
            // Station buildings
            'shipyard' => [
                ['building' => 'robot_factory', 'level' => 2],
            ],
            'research_lab' => [
                // No prerequisites
            ],
            'robot_factory' => [
                // No prerequisites (but called robotics_factory in some contexts)
            ],
            'alliance_depot' => [
                // No prerequisites
            ],
            'missile_silo' => [
                ['building' => 'shipyard', 'level' => 1],
            ],
            'nano_factory' => [
                ['building' => 'robot_factory', 'level' => 10],
                ['building' => 'computer_technology', 'level' => 10], // This is research, but needed
            ],
            'terraformer' => [
                ['building' => 'nano_factory', 'level' => 1],
                ['building' => 'energy_technology', 'level' => 12],
            ],
            'space_dock' => [
                ['building' => 'shipyard', 'level' => 2],
            ],
            
            // Moon buildings
            'lunar_base' => [
                // No prerequisites (but only on moon)
            ],
            'sensor_phalanx' => [
                ['building' => 'lunar_base', 'level' => 1],
            ],
            'jump_gate' => [
                ['building' => 'lunar_base', 'level' => 1],
                ['building' => 'hyperspace_technology', 'level' => 7],
            ],
        ];
    }

    /**
     * Get research prerequisites
     * Returns array of research that requires other research or buildings
     *
     * @return array<string, array{research_lab: int, requirements: array<array{type: string, level: int}>}>
     */
    public static function getResearchPrerequisites(): array
    {
        return [
            'energy_technology' => [
                'research_lab' => 1,
                'requirements' => [],
            ],
            'laser_technology' => [
                'research_lab' => 1,
                'requirements' => [
                    ['type' => 'energy_technology', 'level' => 2],
                ],
            ],
            'ion_technology' => [
                'research_lab' => 4,
                'requirements' => [
                    ['type' => 'laser_technology', 'level' => 5],
                    ['type' => 'energy_technology', 'level' => 4],
                ],
            ],
            'hyperspace_technology' => [
                'research_lab' => 7,
                'requirements' => [
                    ['type' => 'energy_technology', 'level' => 5],
                    ['type' => 'shielding_technology', 'level' => 5],
                ],
            ],
            'plasma_technology' => [
                'research_lab' => 4,
                'requirements' => [
                    ['type' => 'energy_technology', 'level' => 8],
                    ['type' => 'laser_technology', 'level' => 10],
                    ['type' => 'ion_technology', 'level' => 5],
                ],
            ],
            'combustion_drive' => [
                'research_lab' => 1,
                'requirements' => [
                    ['type' => 'energy_technology', 'level' => 1],
                ],
            ],
            'impulse_drive' => [
                'research_lab' => 2,
                'requirements' => [
                    ['type' => 'energy_technology', 'level' => 1],
                ],
            ],
            'hyperspace_drive' => [
                'research_lab' => 7,
                'requirements' => [
                    ['type' => 'hyperspace_technology', 'level' => 3],
                ],
            ],
            'espionage_technology' => [
                'research_lab' => 3,
                'requirements' => [],
            ],
            'computer_technology' => [
                'research_lab' => 1,
                'requirements' => [],
            ],
            'astrophysics' => [
                'research_lab' => 3,
                'requirements' => [
                    ['type' => 'espionage_technology', 'level' => 4],
                    ['type' => 'impulse_drive', 'level' => 3],
                ],
            ],
            'intergalactic_research_network' => [
                'research_lab' => 10,
                'requirements' => [
                    ['type' => 'computer_technology', 'level' => 8],
                    ['type' => 'hyperspace_technology', 'level' => 8],
                ],
            ],
            'graviton_technology' => [
                'research_lab' => 12,
                'requirements' => [],
            ],
            'weapon_technology' => [
                'research_lab' => 4,
                'requirements' => [],
            ],
            'shielding_technology' => [
                'research_lab' => 6,
                'requirements' => [
                    ['type' => 'energy_technology', 'level' => 3],
                ],
            ],
            'armor_technology' => [
                'research_lab' => 2,
                'requirements' => [],
            ],
        ];
    }

    /**
     * Get unit/ship prerequisites
     * Returns array of units that require buildings or research
     *
     * @return array<string, array{shipyard: int, requirements: array<array{type: string, level: int}>}>
     */
    public static function getUnitPrerequisites(): array
    {
        return [
            // Civil ships
            'small_cargo' => [
                'shipyard' => 2,
                'requirements' => [
                    ['type' => 'combustion_drive', 'level' => 2],
                ],
            ],
            'large_cargo' => [
                'shipyard' => 4,
                'requirements' => [
                    ['type' => 'combustion_drive', 'level' => 6],
                ],
            ],
            'colony_ship' => [
                'shipyard' => 4,
                'requirements' => [
                    ['type' => 'impulse_drive', 'level' => 3],
                ],
            ],
            'recycler' => [
                'shipyard' => 4,
                'requirements' => [
                    ['type' => 'combustion_drive', 'level' => 6],
                    ['type' => 'shielding_technology', 'level' => 2],
                ],
            ],
            'espionage_probe' => [
                'shipyard' => 3,
                'requirements' => [
                    ['type' => 'combustion_drive', 'level' => 3],
                    ['type' => 'espionage_technology', 'level' => 2],
                ],
            ],
            'solar_satellite' => [
                'shipyard' => 1,
                'requirements' => [],
            ],
            
            // Military ships
            'light_fighter' => [
                'shipyard' => 1,
                'requirements' => [
                    ['type' => 'combustion_drive', 'level' => 1],
                ],
            ],
            'heavy_fighter' => [
                'shipyard' => 3,
                'requirements' => [
                    ['type' => 'armor_technology', 'level' => 2],
                    ['type' => 'impulse_drive', 'level' => 2],
                ],
            ],
            'cruiser' => [
                'shipyard' => 5,
                'requirements' => [
                    ['type' => 'impulse_drive', 'level' => 4],
                    ['type' => 'ion_technology', 'level' => 2],
                ],
            ],
            'battle_ship' => [
                'shipyard' => 7,
                'requirements' => [
                    ['type' => 'hyperspace_drive', 'level' => 4],
                ],
            ],
            'battlecruiser' => [
                'shipyard' => 8,
                'requirements' => [
                    ['type' => 'hyperspace_technology', 'level' => 5],
                    ['type' => 'hyperspace_drive', 'level' => 5],
                    ['type' => 'laser_technology', 'level' => 12],
                ],
            ],
            'bomber' => [
                'shipyard' => 8,
                'requirements' => [
                    ['type' => 'impulse_drive', 'level' => 6],
                    ['type' => 'plasma_technology', 'level' => 5],
                ],
            ],
            'destroyer' => [
                'shipyard' => 9,
                'requirements' => [
                    ['type' => 'hyperspace_technology', 'level' => 6],
                    ['type' => 'hyperspace_drive', 'level' => 6],
                ],
            ],
            'deathstar' => [
                'shipyard' => 12,
                'requirements' => [
                    ['type' => 'hyperspace_technology', 'level' => 7],
                    ['type' => 'hyperspace_drive', 'level' => 7],
                    ['type' => 'graviton_technology', 'level' => 1],
                ],
            ],
            
            // Defense (requires shipyard)
            'rocket_launcher' => [
                'shipyard' => 1,
                'requirements' => [],
            ],
            'light_laser' => [
                'shipyard' => 2,
                'requirements' => [
                    ['type' => 'energy_technology', 'level' => 2],
                    ['type' => 'laser_technology', 'level' => 3],
                ],
            ],
            'heavy_laser' => [
                'shipyard' => 4,
                'requirements' => [
                    ['type' => 'energy_technology', 'level' => 3],
                    ['type' => 'laser_technology', 'level' => 6],
                ],
            ],
            'gauss_cannon' => [
                'shipyard' => 6,
                'requirements' => [
                    ['type' => 'energy_technology', 'level' => 6],
                    ['type' => 'weapon_technology', 'level' => 3],
                    ['type' => 'shielding_technology', 'level' => 1],
                ],
            ],
            'ion_cannon' => [
                'shipyard' => 4,
                'requirements' => [
                    ['type' => 'ion_technology', 'level' => 4],
                ],
            ],
            'plasma_turret' => [
                'shipyard' => 8,
                'requirements' => [
                    ['type' => 'plasma_technology', 'level' => 7],
                ],
            ],
            'small_shield_dome' => [
                'shipyard' => 1,
                'requirements' => [
                    ['type' => 'shielding_technology', 'level' => 2],
                ],
            ],
            'large_shield_dome' => [
                'shipyard' => 6,
                'requirements' => [
                    ['type' => 'shielding_technology', 'level' => 6],
                ],
            ],
            'anti_ballistic_missile' => [
                'shipyard' => 1,
                'requirements' => [],
            ],
            'interplanetary_missile' => [
                'shipyard' => 1,
                'requirements' => [
                    ['type' => 'impulse_drive', 'level' => 1],
                ],
            ],
        ];
    }
}
