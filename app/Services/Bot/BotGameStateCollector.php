<?php

namespace OGame\Services\Bot;

use OGame\Models\User;
use OGame\Services\PlayerService;
use OGame\Services\PlanetService;

/**
 * Bot Game State Collector
 * 
 * Collects comprehensive game state for bot decision making.
 * Returns minified data to reduce AI token usage.
 */
class BotGameStateCollector
{
    /**
     * Collect complete game state for a bot
     * 
     * @param User $bot
     * @return array Minified game state
     */
    public function collectGameState(User $bot): array
    {
        $player = new PlayerService($bot->id);
        
        // Update player state (same as GlobalGame middleware does for regular players)
        // 1. Update research queue and player timestamp
        $player->update();
        
        // 2. Update fleet missions (process arrived missions)
        $player->updateFleetMissions();
        
        // 3. Planet updates happen in collectPlanets() method
        
        return [
            'bot_id' => $bot->id,
            'strategy' => $bot->bot_strategy,
            'skill_level' => $bot->bot_skill_level,
            'planets' => $this->collectPlanets($player),
            'research' => $this->collectResearch($player),
            'fleet' => $this->collectFleet($player),
            'threats' => $this->collectThreats($player),
            'opportunities' => $this->collectOpportunities($player),
        ];
    }

    /**
     * Collect planet data (minified)
     */
    protected function collectPlanets(PlayerService $player): array
    {
        $planets = [];
        
        foreach ($player->planets->all() as $planet) {
            // Update planet resources before collecting state
            // Wrap in try-catch to prevent division by zero errors from breaking bot turns
            try {
                $planet->update();
            } catch (\DivisionByZeroError $e) {
                // Log error but continue with other planets
                \Log::channel('bot')->warning("Bot GameStateCollector: Division by zero error on planet {$planet->getPlanetId()}: " . $e->getMessage());
                // Continue to next planet
                continue;
            } catch (\Exception $e) {
                // Log other errors but continue
                \Log::channel('bot')->warning("Bot GameStateCollector: Error updating planet {$planet->getPlanetId()}: " . $e->getMessage());
                continue;
            }
            
            // Include all active planets (never skip - even new planets start with 0 resources)
            // The bot needs to see all planets to build production facilities

            $planets[] = [
                'planet_id' => $planet->getPlanetId(),
                'coordinates' => $planet->getPlanetCoordinates(),
                'name' => $planet->getPlanetName(),
                
                // Planet properties
                'diameter' => $planet->getPlanetDiameter(),
                'field_max' => $planet->getPlanetFieldMax(),
                
                // Resources
                'metal_stored' => (int)$planet->metal()->get(),
                'crystal_stored' => (int)$planet->crystal()->get(),
                'deuterium_stored' => (int)$planet->deuterium()->get(),
                'energy_available' => $planet->energy()->get(),
                
                // Production rates (per hour)
                'metal_production' => (int)$planet->getMetalProductionPerHour(),
                'crystal_production' => (int)$planet->getCrystalProductionPerHour(),
                'deuterium_production' => (int)$planet->getDeuteriumProductionPerHour(),
                
                // Storage capacity
                'metal_capacity' => $planet->metalStorage()->get(),
                'crystal_capacity' => $planet->crystalStorage()->get(),
                'deuterium_capacity' => $planet->deuteriumStorage()->get(),
                
                // All buildings (AI needs complete info to make decisions)
                'buildings' => $this->getAllBuildings($planet),
                
                // Defense structures
                'defenses' => $this->getDefenses($planet),
                
                // Ships on planet
                'ships' => $this->getShips($planet),
                
                // Queue status with details
                'build_queue_busy' => $this->isBuildQueueBusy($planet),
                'build_queue_count' => $this->getBuildQueueCount($planet),
                'build_queue_items' => $this->getBuildQueueItems($planet),
                'unit_queue_busy' => $this->isUnitQueueBusy($planet),
                'unit_queue_items' => $this->getUnitQueueItems($planet),
            ];
        }

        return $planets;
    }

    /**
     * Get all buildings with their levels
     */
    protected function getAllBuildings(PlanetService $planet): array
    {
        $buildings = [];
        
        // Get all building types with correct machine names
        $buildingTypes = [
            // Production
            'metal_mine', 'crystal_mine', 'deuterium_synthesizer',
            'solar_plant', 'fusion_plant',
            // Storage
            'metal_store', 'crystal_store', 'deuterium_store',
            // Facilities
            'robot_factory', 'shipyard', 'research_lab',
            'alliance_depot', 'missile_silo', 'nano_factory', 'terraformer',
            'lunar_base', 'sensor_phalanx', 'jump_gate',
        ];

        foreach ($buildingTypes as $type) {
            try {
                $level = $planet->getObjectLevel($type);
                // Include ALL buildings, even level 0 (AI needs to know what's NOT built)
                $buildings[] = [
                    'type' => $type,
                    'level' => $level,
                ];
            } catch (\Exception $e) {
                // Building not found in game, skip
            }
        }

        return $buildings;
    }

    /**
     * Get defense structures on planet
     */
    protected function getDefenses(PlanetService $planet): array
    {
        $defenses = [];
        
        $defenseTypes = [
            'rocket_launcher', 'light_laser', 'heavy_laser', 
            'gauss_cannon', 'ion_cannon', 'plasma_turret',
            'small_shield_dome', 'large_shield_dome',
            'anti_ballistic_missile', 'interplanetary_missile',
        ];
        
        foreach ($defenseTypes as $defense) {
            try {
                $count = $planet->getObjectAmount($defense);
                if ($count > 0) {
                    $defenses[] = [
                        'type' => $defense,
                        'count' => $count,
                    ];
                }
            } catch (\Exception $e) {
                // Defense not found
            }
        }
        
        return $defenses;
    }

    /**
     * Get ships on planet
     */
    protected function getShips(PlanetService $planet): array
    {
        $ships = [];
        
        $shipTypes = [
            // Combat ships
            'light_fighter', 'heavy_fighter', 'cruiser', 'battleship', 
            'battlecruiser', 'bomber', 'destroyer', 'deathstar',
            // Civil ships
            'small_cargo', 'large_cargo', 'colony_ship', 'recycler', 
            'espionage_probe', 'solar_satellite',
        ];
        
        foreach ($shipTypes as $ship) {
            try {
                $count = $planet->getObjectAmount($ship);
                if ($count > 0) {
                    $ships[] = [
                        'type' => $ship,
                        'count' => $count,
                    ];
                }
            } catch (\Exception $e) {
                // Ship not found
            }
        }
        
        return $ships;
    }

    /**
     * Check if build queue is busy
     */
    protected function isBuildQueueBusy(PlanetService $planet): bool
    {
        try {
            $buildingQueueService = app(\OGame\Services\BuildingQueueService::class);
            $queue = $buildingQueueService->retrieveQueue($planet);
            return $queue->isQueueFull();
        } catch (\Exception $e) {
            // Error checking queue, assume not busy
            return false;
        }
    }

    /**
     * Get build queue count
     */
    protected function getBuildQueueCount(PlanetService $planet): int
    {
        try {
            return \DB::table('building_queues')
                ->where('planet_id', $planet->getPlanetId())
                ->where('processed', 0)
                ->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Check if unit queue is busy
     */
    protected function isUnitQueueBusy(PlanetService $planet): bool
    {
        try {
            // Check if there's an active unit queue for this planet
            return \DB::table('unit_queues')
                ->where('planet_id', $planet->getPlanetId())
                ->exists();
        } catch (\Exception $e) {
            // Error checking queue, assume not busy
            return false;
        }
    }

    /**
     * Collect research status (minified)
     */
    protected function collectResearch(PlayerService $player): array
    {
        $research = [];
        
        // ALL research technologies (AI needs complete picture)
        $techTypes = [
            // Energy & Drives
            'energy_technology',
            'combustion_drive',
            'impulse_drive',
            'hyperspace_drive',
            'hyperspace_technology',
            // Weapons & Defense
            'laser_technology',
            'ion_technology',
            'plasma_technology',
            'weapon_technology',
            'shielding_technology',
            'armor_technology',
            // Economy & Utility
            'computer_technology',
            'espionage_technology',
            'astrophysics',
            'intergalactic_research_network',
            'graviton_technology',
        ];

        foreach ($techTypes as $tech) {
            try {
                $level = $player->getResearchLevel($tech);
                // Include ALL techs (even level 0) so AI knows what's available
                $research[] = [
                    'tech_type' => $tech,
                    'current_level' => $level,
                ];
            } catch (\Exception $e) {
                // Tech not available in game
            }
        }

        return [
            'technologies' => $research,
            'research_queue_busy' => $this->isResearchQueueBusy($player),
            'research_queue_items' => $this->getResearchQueueItems($player),
        ];
    }

    /**
     * Check if research queue is busy
     */
    protected function isResearchQueueBusy(PlayerService $player): bool
    {
        try {
            // Check if there's an active research
            return \DB::table('research_queues')
                ->join('planets', 'research_queues.planet_id', '=', 'planets.id')
                ->where('planets.user_id', $player->getId())
                ->where('research_queues.processed', 0)
                ->where('research_queues.canceled', 0)
                ->exists();
        } catch (\Exception $e) {
            // Table doesn't exist or error, assume not busy
            return false;
        }
    }

    /**
     * Collect fleet information (minified)
     */
    protected function collectFleet(PlayerService $player): array
    {
        $totalShips = 0;
        $fleetComposition = [];

        foreach ($player->planets->all() as $planet) {
            // Get ALL ship types
            $shipTypes = [
                // Combat ships
                'light_fighter', 'heavy_fighter', 'cruiser', 'battleship', 
                'battlecruiser', 'bomber', 'destroyer', 'deathstar',
                // Civil ships
                'small_cargo', 'large_cargo', 'colony_ship', 'recycler', 
                'espionage_probe', 'solar_satellite',
            ];
            
            foreach ($shipTypes as $ship) {
                try {
                    $count = $planet->getObjectAmount($ship);
                    if ($count > 0) {
                        $fleetComposition[$ship] = ($fleetComposition[$ship] ?? 0) + $count;
                        $totalShips += $count;
                    }
                } catch (\Exception $e) {
                    // Ship type not found
                }
            }
        }

        // Get active fleet missions
        $activeMissions = $this->getActiveFleetMissions($player);
        
        // Get fleet slots
        $slotsUsed = $player->getFleetSlotsInUse();
        $slotsMax = $player->getFleetSlotsMax();
        $expeditionSlotsUsed = $player->getExpeditionSlotsInUse();
        $expeditionSlotsMax = $player->getExpeditionSlotsMax();

        return [
            'total_ships' => $totalShips,
            'composition' => $fleetComposition,
            'active_missions' => $activeMissions,
            'fleet_slots_used' => $slotsUsed,
            'fleet_slots_max' => $slotsMax,
            'expedition_slots_used' => $expeditionSlotsUsed,
            'expedition_slots_max' => $expeditionSlotsMax,
        ];
    }
    
    /**
     * Get active fleet missions
     */
    protected function getActiveFleetMissions(PlayerService $player): array
    {
        $missions = [];
        
        try {
            $activeFleets = \DB::table('fleet_missions')
                ->where('user_id', $player->getId())
                ->where('canceled', 0)
                ->get();
            
            foreach ($activeFleets as $fleet) {
                $missions[] = [
                    'mission_type' => $fleet->mission_type ?? 'unknown',
                    'from_planet' => $fleet->planet_id_from ?? null,
                    'to_planet' => $fleet->planet_id_to ?? null,
                    'arrival_time' => $fleet->time_arrival ?? null,
                    'return_time' => $fleet->time_return ?? null,
                ];
            }
        } catch (\Exception $e) {
            // Error getting missions
        }
        
        return $missions;
    }

    /**
     * Collect threat assessment
     */
    protected function collectThreats(PlayerService $player): array
    {
        $threats = [];
        
        // Get planet IDs
        $planetIds = [];
        foreach ($player->planets->all() as $planet) {
            $planetIds[] = $planet->getPlanetId();
        }
        
        if (empty($planetIds)) {
            return [
                'incoming_attacks' => 0,
                'threats' => [],
                'defense_gap' => 0,
            ];
        }
        
        // Check for incoming attacks
        try {
            $incomingAttacks = \DB::table('fleet_missions')
                ->where('destination_planet_id', '!=', null)
                ->where('mission_type', 1) // Attack mission
                ->whereIn('destination_planet_id', $planetIds)
                ->where('arrival_time', '>', now())
                ->get();

            foreach ($incomingAttacks as $attack) {
                $threats[] = [
                    'type' => 'incoming_attack',
                    'arrival_time' => $attack->arrival_time,
                    'from_user_id' => $attack->user_id,
                ];
            }
        } catch (\Exception $e) {
            // Error checking threats, return empty
        }

        return [
            'incoming_attacks' => count($threats),
            'threats' => $threats,
            'defense_gap' => $this->calculateDefenseGap($player),
        ];
    }

    /**
     * Calculate defense gap (simplified)
     */
    protected function calculateDefenseGap(PlayerService $player): int
    {
        // Simplified: return negative if defense needed
        $totalDefense = 0;
        
        // ALL defense types
        $defenseTypes = [
            'rocket_launcher', 'light_laser', 'heavy_laser', 
            'gauss_cannon', 'ion_cannon', 'plasma_turret',
            'small_shield_dome', 'large_shield_dome',
            'anti_ballistic_missile', 'interplanetary_missile',
        ];
        
        foreach ($player->planets->all() as $planet) {
            foreach ($defenseTypes as $defense) {
                try {
                    $totalDefense += $planet->getObjectAmount($defense) ?? 0;
                } catch (\Exception $e) {
                    // Defense not found
                }
            }
        }

        // If less than 100 total defense structures, return gap
        return $totalDefense < 100 ? (100 - $totalDefense) : 0;
    }

    /**
     * Collect raid opportunities (minified)
     */
    protected function collectOpportunities(PlayerService $player): array
    {
        // Simplified: return empty for now
        // In full implementation, would scan nearby players for weak targets
        return [
            'raid_targets' => [],
            'colonization_spots' => $this->findColonizationSpots($player),
        ];
    }

    /**
     * Find available colonization spots
     */
    protected function findColonizationSpots(PlayerService $player): array
    {
        // Check if player can colonize (has colony ship and available slots)
        $currentPlanetCount = 0;
        foreach ($player->planets->all() as $planet) {
            $currentPlanetCount++;
        }
        
        $maxPlanets = 9; // Adjust based on game rules
        
        if ($currentPlanetCount >= $maxPlanets) {
            return [];
        }

        // Return simplified colonization data
        return [
            'available_slots' => $maxPlanets - $currentPlanetCount,
            'has_colony_ship' => false, // TODO: Check actual colony ship count
        ];
    }

    /**
     * Get building queue items with details
     */
    protected function getBuildQueueItems(PlanetService $planet): array
    {
        $items = [];
        
        try {
            $queueItems = \DB::table('building_queues')
                ->where('planet_id', $planet->getPlanetId())
                ->where('processed', 0)
                ->where('canceled', 0)
                ->orderBy('time_start', 'asc')
                ->get();
            
            foreach ($queueItems as $item) {
                try {
                    $object = \OGame\Services\ObjectService::getObjectById($item->object_id);
                    $timeRemaining = max(0, $item->time_end - time());
                    
                    $items[] = [
                        'building' => $object->machine_name,
                        'level' => $item->object_level_target,
                        'is_building' => (bool)$item->building,
                        'time_remaining_seconds' => $timeRemaining,
                    ];
                } catch (\Exception $e) {
                    // Object not found, skip
                }
            }
        } catch (\Exception $e) {
            // Error getting queue items
        }
        
        return $items;
    }

    /**
     * Get unit queue items with details (includes both ships and defense)
     */
    protected function getUnitQueueItems(PlanetService $planet): array
    {
        $items = [];
        
        try {
            $queueItems = \DB::table('unit_queues')
                ->where('planet_id', $planet->getPlanetId())
                ->where('processed', 0)
                ->orderBy('time_start', 'asc')
                ->get();
            
            // Get defense object IDs to distinguish ships from defense
            $defenseObjectIds = array_column(\OGame\Services\ObjectService::getDefenseObjects(), 'id');
            
            foreach ($queueItems as $item) {
                try {
                    $object = \OGame\Services\ObjectService::getObjectById($item->object_id);
                    $timeRemaining = max(0, $item->time_end - time());
                    $remaining = $item->object_amount - ($item->object_amount_progress ?? 0);
                    
                    // Determine if this is a defense structure or a ship
                    $isDefense = in_array($item->object_id, $defenseObjectIds);
                    
                    $items[] = [
                        'unit' => $object->machine_name,
                        'type' => $isDefense ? 'defense' : 'ship',
                        'quantity' => $item->object_amount,
                        'remaining' => $remaining,
                        'time_remaining_seconds' => $timeRemaining,
                    ];
                } catch (\Exception $e) {
                    // Object not found, skip
                }
            }
        } catch (\Exception $e) {
            // Error getting queue items
        }
        
        return $items;
    }

    /**
     * Get research queue items with details
     */
    protected function getResearchQueueItems(PlayerService $player): array
    {
        $items = [];
        
        try {
            $queueItems = \DB::table('research_queues')
                ->join('planets', 'research_queues.planet_id', '=', 'planets.id')
                ->where('planets.user_id', $player->getId())
                ->where('research_queues.processed', 0)
                ->where('research_queues.canceled', 0)
                ->select('research_queues.*')
                ->orderBy('research_queues.time_start', 'asc')
                ->get();
            
            foreach ($queueItems as $item) {
                try {
                    $object = \OGame\Services\ObjectService::getResearchObjectById($item->object_id);
                    $timeRemaining = max(0, $item->time_end - time());
                    
                    $items[] = [
                        'technology' => $object->machine_name,
                        'level' => $item->object_level_target,
                        'planet_id' => $item->planet_id,
                        'is_building' => (bool)$item->building,
                        'time_remaining_seconds' => $timeRemaining,
                    ];
                } catch (\Exception $e) {
                    // Object not found, skip
                }
            }
        } catch (\Exception $e) {
            // Error getting queue items
        }
        
        return $items;
    }
}
