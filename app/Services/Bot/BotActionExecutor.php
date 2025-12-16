<?php

namespace OGame\Services\Bot;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\User;
use OGame\Services\BuildingQueueService;
use OGame\Services\PlayerService;
use OGame\Services\ResearchQueueService;

/**
 * Bot Action Executor
 *
 * Executes bot decisions by calling existing game services
 */
class BotActionExecutor
{
    public function __construct(
        protected BuildingQueueService $buildingQueueService,
        protected ResearchQueueService $researchQueueService,
        protected PlanetServiceFactory $planetServiceFactory
    ) {
    }

    /**
     * Get bot log channel
     */
    protected function botLog()
    {
        return Log::channel('bot');
    }

    /**
     * Execute all actions from a decision
     *
     * @param User $bot
     * @param array $decision
     * @param string $turnId
     * @return array Results of each action
     */
    public function executeDecisions(User $bot, array $decision, string $turnId): array
    {
        $results = [];
        $player = new PlayerService($bot->id);

        foreach ($decision['actions'] as $index => $action) {
            $actionType = $action['action_type'];
            $idempotencyKey = "{$turnId}:{$index}";

            // Check idempotency
            if ($this->isActionExecuted($idempotencyKey)) {
                $this->botLog()->info("Action already executed: {$idempotencyKey}");
                continue;
            }

            // Execute action
            try {
                $result = $this->executeAction($bot, $player, $action);
                $results[] = [
                    'action' => $action,
                    'result' => $result,
                    'idempotency_key' => $idempotencyKey,
                ];

                // Update decision record
                DB::table('bot_decisions_active')
                    ->where('idempotency_key', $idempotencyKey)
                    ->update([
                        'result' => $result['success'] ? 'success' : 'failed',
                        'error_message' => $result['error'] ?? null,
                        'updated_at' => now(),
                    ]);
            } catch (Exception $e) {
                $this->botLog()->error("[ACTION_FAILED] Bot:{$bot->id} Error:" . str_replace([' ', "\n"], ['_', ''], substr($e->getMessage(), 0, 50)) . " | Bot {$bot->id} action failed: " . $e->getMessage());

                $results[] = [
                    'action' => $action,
                    'result' => ['success' => false, 'error' => $e->getMessage()],
                    'idempotency_key' => $idempotencyKey,
                ];

                // Update decision record with error
                DB::table('bot_decisions_active')
                    ->where('idempotency_key', $idempotencyKey)
                    ->update([
                        'result' => 'error',
                        'error_message' => $e->getMessage(),
                        'updated_at' => now(),
                    ]);
            }
        }

        return $results;
    }

    /**
     * Check if action already executed
     */
    protected function isActionExecuted(string $idempotencyKey): bool
    {
        $decision = DB::table('bot_decisions_active')
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        return $decision && in_array($decision->result, ['success', 'failed', 'error']);
    }

    /**
     * Execute a single action
     *
     * @param User $bot
     * @param PlayerService $player
     * @param array $action
     * @return array
     */
    protected function executeAction(User $bot, PlayerService $player, array $action): array
    {
        $actionType = $action['action_type'];

        return match($actionType) {
            // Building actions
            'BUILD_BUILDING' => $this->executeBuildBuilding($player, $action),
            'CANCEL_BUILDING' => $this->executeCancelBuilding($player, $action),
            'DEMOLISH_BUILDING' => $this->executeDemolishBuilding($player, $action),

            // Research actions
            'START_RESEARCH' => $this->executeStartResearch($player, $action),
            'CANCEL_RESEARCH' => $this->executeCancelResearch($player, $action),

            // Unit production actions
            'BUILD_UNITS' => $this->executeBuildUnits($player, $action),
            'CANCEL_UNITS' => $this->executeCancelUnits($player, $action),

            // Fleet operations
            'SEND_FLEET' => $this->executeSendFleet($player, $action),
            'CANCEL_FLEET' => $this->executeCancelFleet($player, $action),
            'TRANSPORT_RESOURCES' => $this->executeTransportResources($player, $action),
            'DEPLOY_FLEET' => $this->executeDeployFleet($player, $action),
            'ATTACK' => $this->executeAttack($player, $action),
            'SPY' => $this->executeSpy($player, $action),
            'COLONIZE' => $this->executeColonize($player, $action),
            'EXPEDITION' => $this->executeExpedition($player, $action),
            'HARVEST_DEBRIS' => $this->executeHarvestDebris($player, $action),

            // Resource management
            'ABANDON_PLANET' => $this->executeAbandonPlanet($player, $action),

            // Other
            'WAIT' => $this->executeWait($action),

            default => ['success' => false, 'error' => "Unknown action type: {$actionType}"],
        };
    }

    /**
     * Execute BUILD_BUILDING action
     */
    protected function executeBuildBuilding(PlayerService $player, array $action): array
    {
        $planetId = $action['planet_id'] ?? null;
        $buildingName = $action['target'] ?? null;

        if (!$planetId || !$buildingName) {
            return ['success' => false, 'error' => 'Missing planet_id or target'];
        }

        try {
            // Get planet service
            $planet = $player->planets->getById($planetId);

            // Get building object by machine name
            $building = \OGame\Services\ObjectService::getObjectByMachineName($buildingName);
            if (!$building) {
                return ['success' => false, 'error' => "Building not found: {$buildingName}"];
            }

            // Check if building queue is full
            $buildQueue = $this->buildingQueueService->retrieveQueue($planet);
            if ($buildQueue->isQueueFull()) {
                return ['success' => false, 'error' => 'Building queue is full'];
            }

            // Check if building can be built (requirements, resources, etc.)
            $currentLevel = $planet->getObjectLevel($buildingName);
            $nextLevel = $currentLevel + 1;

            // Check requirements
            $requirementsMet = \OGame\Services\ObjectService::objectRequirementsWithLevelsMet($buildingName, $nextLevel, $planet);
            if (!$requirementsMet) {
                return ['success' => false, 'error' => 'Requirements not met'];
            }

            // Check resources
            $price = \OGame\Services\ObjectService::getObjectPrice($buildingName, $planet);
            if (!$planet->hasResources($price)) {
                return ['success' => false, 'error' => 'Insufficient resources'];
            }

            // Add to building queue
            $this->buildingQueueService->add($planet, $building->id);

            $this->botLog()->info("[ACTION_BUILD_BUILDING] Bot:{$player->getId()} Type:BUILD_BUILDING Target:{$buildingName} Level:{$nextLevel} Planet:{$planetId} | Bot {$player->getId()} started building {$buildingName} level {$nextLevel} on planet {$planetId}");

            return [
                'success' => true,
                'building' => $buildingName,
                'level' => $nextLevel,
                'planet_id' => $planetId,
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Execute START_RESEARCH action
     */
    protected function executeStartResearch(PlayerService $player, array $action): array
    {
        $planetId = $action['planet_id'] ?? null;
        $techName = $action['target'] ?? null;

        if (!$planetId) {
            return ['success' => false, 'error' => 'Missing planet_id (required for START_RESEARCH)'];
        }

        if (!$techName) {
            return ['success' => false, 'error' => 'Missing target (technology name)'];
        }

        try {
            // Get planet by ID
            $planet = $player->planets->getById($planetId);
            if (!$planet) {
                return ['success' => false, 'error' => "Planet not found: {$planetId}"];
            }

            // Check if planet has research lab
            $labLevel = $planet->getObjectLevel('research_lab');
            if ($labLevel <= 0) {
                return ['success' => false, 'error' => "Planet {$planetId} does not have a research lab"];
            }

            // Get technology object by machine name
            $technology = \OGame\Services\ObjectService::getObjectByMachineName($techName);
            if (!$technology) {
                return ['success' => false, 'error' => "Technology not found: {$techName}"];
            }

            // Get current tech level and calculate next level
            $currentLevel = $player->getResearchLevel($techName);
            $amountInQueue = $this->researchQueueService->activeResearchQueueItemCount($player, $technology->id);
            $nextLevel = $currentLevel + $amountInQueue + 1;

            // Check if research queue is busy
            $researchQueue = $this->researchQueueService->retrieveQueue($planet);
            if ($researchQueue->isQueueFull()) {
                return ['success' => false, 'error' => 'Research queue is busy'];
            }

            // Check requirements (use objectRequirementsMetWithQueue to match ResearchQueueService behavior)
            $requirementsMet = \OGame\Services\ObjectService::objectRequirementsMetWithQueue($techName, $nextLevel, $planet);
            if (!$requirementsMet) {
                return ['success' => false, 'error' => 'Requirements not met'];
            }

            // Check resources - use getObjectRawPrice for specific level
            $price = \OGame\Services\ObjectService::getObjectRawPrice($techName, $nextLevel);
            if (!$planet->hasResources($price)) {
                return ['success' => false, 'error' => 'Insufficient resources'];
            }

            // Add to research queue
            $this->researchQueueService->add($player, $planet, $technology->id);

            $this->botLog()->info("[ACTION_START_RESEARCH] Bot:{$player->getId()} Type:START_RESEARCH Target:{$techName} Level:{$nextLevel} | Bot {$player->getId()} started researching {$techName} level {$nextLevel}");

            return [
                'success' => true,
                'technology' => $techName,
                'level' => $nextLevel,
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Execute BUILD_UNITS action
     */
    protected function executeBuildUnits(PlayerService $player, array $action): array
    {
        $planetId = $action['planet_id'] ?? null;
        $unitName = $action['target'] ?? null;
        $quantity = $action['quantity'] ?? 1;

        if (!$planetId || !$unitName) {
            return ['success' => false, 'error' => 'Missing planet_id or target (unit name)'];
        }

        try {
            // Get planet service
            $planet = $player->planets->getById($planetId);

            // Get unit object by machine name
            $unit = \OGame\Services\ObjectService::getUnitObjectByMachineName($unitName);
            if (!$unit) {
                return ['success' => false, 'error' => "Unit not found: {$unitName}"];
            }

            // Check if unit queue is busy
            $unitQueue = DB::table('unit_queues')
                ->where('planet_id', $planetId)
                ->exists();

            if ($unitQueue) {
                return ['success' => false, 'error' => 'Unit queue is busy'];
            }

            // Check requirements
            $requirementsMet = \OGame\Services\ObjectService::objectRequirementsMet($unitName, $planet);
            if (!$requirementsMet) {
                return ['success' => false, 'error' => 'Requirements not met'];
            }

            // Check resources for the quantity requested
            $price = \OGame\Services\ObjectService::getObjectPrice($unitName, $planet);
            $totalPrice = new \OGame\Models\Resources(
                $price->metal->get() * $quantity,
                $price->crystal->get() * $quantity,
                $price->deuterium->get() * $quantity
            );

            if (!$planet->hasResources($totalPrice)) {
                return ['success' => false, 'error' => 'Insufficient resources'];
            }

            // Add to unit queue
            DB::table('unit_queues')->insert([
                'planet_id' => $planetId,
                'object_id' => $unit->id,
                'object_amount' => $quantity,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Deduct resources
            $planet->deductResources($totalPrice);

            $this->botLog()->info("[ACTION_BUILD_UNITS] Bot:{$player->getId()} Type:BUILD_UNITS Target:{$unitName} Quantity:{$quantity} Planet:{$planetId} | Bot {$player->getId()} started building {$quantity}x {$unitName} on planet {$planetId}");

            return [
                'success' => true,
                'unit' => $unitName,
                'quantity' => $quantity,
                'planet_id' => $planetId,
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Execute SEND_FLEET action
     */
    protected function executeSendFleet(PlayerService $player, array $action): array
    {
        // TODO: Implement fleet sending
        // This is complex and requires:
        // - Fleet composition validation
        // - Target coordinates validation
        // - Mission type validation
        // - Flight time calculation
        // - Resource loading (for transport missions)

        return [
            'success' => false,
            'error' => 'SEND_FLEET action not yet fully implemented. Coming soon!',
        ];
    }

    /**
     * Execute WAIT action (no-op)
     */
    protected function executeWait(array $action): array
    {
        return [
            'success' => true,
            'action' => 'WAIT',
            'message' => 'Bot waiting for resources or queue availability',
        ];
    }

    /**
     * Execute CANCEL_BUILDING action
     */
    protected function executeCancelBuilding(PlayerService $player, array $action): array
    {
        $planetId = $action['planet_id'] ?? null;
        $queueId = $action['queue_id'] ?? null;

        if (!$planetId) {
            return ['success' => false, 'error' => 'Missing planet_id'];
        }

        try {
            $planet = $player->planets->getById($planetId);

            // Get first building in queue if no specific queue_id provided
            if (!$queueId) {
                $queueItem = DB::table('building_queues')
                    ->where('planet_id', $planetId)
                    ->where('processed', 0)
                    ->orderBy('time_start', 'asc')
                    ->first();

                if (!$queueItem) {
                    return ['success' => false, 'error' => 'No building in queue'];
                }
                $queueId = $queueItem->id;
            }

            $this->buildingQueueService->cancel($planet, $queueId);

            $this->botLog()->info("[ACTION_CANCEL_BUILDING] Bot:{$player->getId()} Type:CANCEL_BUILDING QueueId:{$queueId} Planet:{$planetId} | Bot {$player->getId()} canceled building queue item {$queueId} on planet {$planetId}");

            return ['success' => true, 'queue_id' => $queueId];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Execute DEMOLISH_BUILDING action
     */
    protected function executeDemolishBuilding(PlayerService $player, array $action): array
    {
        // Note: OGame typically doesn't allow demolishing buildings
        // This would need custom implementation if desired
        return [
            'success' => false,
            'error' => 'DEMOLISH_BUILDING not supported in standard OGame mechanics',
        ];
    }

    /**
     * Execute CANCEL_RESEARCH action
     */
    protected function executeCancelResearch(PlayerService $player, array $action): array
    {
        try {
            $planet = $player->planets->current();

            // Get current research in progress
            $queueItem = DB::table('research_queues')
                ->join('users', 'research_queues.user_id', '=', 'users.id')
                ->where('users.id', $player->getId())
                ->where('research_queues.processed', 0)
                ->where('research_queues.canceled', 0)
                ->orderBy('research_queues.time_start', 'asc')
                ->select('research_queues.*')
                ->first();

            if (!$queueItem) {
                return ['success' => false, 'error' => 'No research in progress'];
            }

            $this->researchQueueService->cancel($player, $queueItem->id, $queueItem->object_id);

            $this->botLog()->info("[ACTION_CANCEL_RESEARCH] Bot:{$player->getId()} Type:CANCEL_RESEARCH QueueId:{$queueItem->id} | Bot {$player->getId()} canceled research queue item {$queueItem->id}");

            return ['success' => true, 'queue_id' => $queueItem->id];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Execute CANCEL_UNITS action
     */
    protected function executeCancelUnits(PlayerService $player, array $action): array
    {
        $planetId = $action['planet_id'] ?? null;

        if (!$planetId) {
            return ['success' => false, 'error' => 'Missing planet_id'];
        }

        try {
            $planet = $player->planets->getById($planetId);

            // Get unit queue
            $queueItem = DB::table('unit_queues')
                ->where('planet_id', $planetId)
                ->first();

            if (!$queueItem) {
                return ['success' => false, 'error' => 'No units in production'];
            }

            // Refund resources
            $unit = \OGame\Services\ObjectService::getObjectById($queueItem->object_id);
            $price = \OGame\Services\ObjectService::getObjectPrice($unit->machine_name, $planet);
            $totalPrice = new \OGame\Models\Resources(
                $price->metal->get() * $queueItem->object_amount,
                $price->crystal->get() * $queueItem->object_amount,
                $price->deuterium->get() * $queueItem->object_amount
            );

            $planet->addResources($totalPrice);

            // Delete queue item
            DB::table('unit_queues')->where('id', $queueItem->id)->delete();

            $this->botLog()->info("[ACTION_CANCEL_UNITS] Bot:{$player->getId()} Type:CANCEL_UNITS Planet:{$planetId} | Bot {$player->getId()} canceled unit production on planet {$planetId}");

            return ['success' => true, 'planet_id' => $planetId];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Execute CANCEL_FLEET action
     */
    protected function executeCancelFleet(PlayerService $player, array $action): array
    {
        $fleetId = $action['fleet_id'] ?? null;

        if (!$fleetId) {
            return ['success' => false, 'error' => 'Missing fleet_id'];
        }

        try {
            $fleet = DB::table('fleet_missions')
                ->where('id', $fleetId)
                ->where('user_id', $player->getId())
                ->where('processed', 0)
                ->where('canceled', 0)
                ->first();

            if (!$fleet) {
                return ['success' => false, 'error' => 'Fleet not found or already processed'];
            }

            // Use FleetMissionService to cancel
            $fleetService = app(\OGame\Services\FleetMissionService::class);
            $fleetMission = \OGame\Models\FleetMission::find($fleetId);

            if ($fleetMission) {
                $fleetService->cancelMission($fleetMission);

                $this->botLog()->info("[ACTION_CANCEL_FLEET] Bot:{$player->getId()} Type:CANCEL_FLEET FleetId:{$fleetId} | Bot {$player->getId()} canceled fleet mission {$fleetId}");

                return ['success' => true, 'fleet_id' => $fleetId];
            }

            return ['success' => false, 'error' => 'Fleet mission not found'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Execute TRANSPORT_RESOURCES action
     */
    protected function executeTransportResources(PlayerService $player, array $action): array
    {
        return [
            'success' => false,
            'error' => 'TRANSPORT_RESOURCES not yet implemented. Use SEND_FLEET with mission type transport.',
        ];
    }

    /**
     * Execute DEPLOY_FLEET action
     */
    protected function executeDeployFleet(PlayerService $player, array $action): array
    {
        return [
            'success' => false,
            'error' => 'DEPLOY_FLEET not yet implemented. Use SEND_FLEET with mission type deploy.',
        ];
    }

    /**
     * Execute ATTACK action
     */
    protected function executeAttack(PlayerService $player, array $action): array
    {
        return [
            'success' => false,
            'error' => 'ATTACK not yet implemented. Use SEND_FLEET with mission type attack.',
        ];
    }

    /**
     * Execute SPY action
     */
    protected function executeSpy(PlayerService $player, array $action): array
    {
        return [
            'success' => false,
            'error' => 'SPY not yet implemented. Use SEND_FLEET with mission type spy.',
        ];
    }

    /**
     * Execute COLONIZE action
     */
    protected function executeColonize(PlayerService $player, array $action): array
    {
        return [
            'success' => false,
            'error' => 'COLONIZE not yet implemented. Use SEND_FLEET with mission type colonize.',
        ];
    }

    /**
     * Execute EXPEDITION action
     */
    protected function executeExpedition(PlayerService $player, array $action): array
    {
        return [
            'success' => false,
            'error' => 'EXPEDITION not yet implemented. Use SEND_FLEET with mission type expedition.',
        ];
    }

    /**
     * Execute HARVEST_DEBRIS action
     */
    protected function executeHarvestDebris(PlayerService $player, array $action): array
    {
        return [
            'success' => false,
            'error' => 'HARVEST_DEBRIS not yet implemented. Use SEND_FLEET with mission type harvest.',
        ];
    }

    /**
     * Execute ABANDON_PLANET action
     */
    protected function executeAbandonPlanet(PlayerService $player, array $action): array
    {
        $planetId = $action['planet_id'] ?? null;

        if (!$planetId) {
            return ['success' => false, 'error' => 'Missing planet_id'];
        }

        try {
            // Safety check: don't abandon if it's the only planet
            if ($player->planets->count() <= 1) {
                return ['success' => false, 'error' => 'Cannot abandon last planet'];
            }

            // Safety check: don't abandon main planet
            $planet = $player->planets->getById($planetId);
            if ($planet->isMainPlanet()) {
                return ['success' => false, 'error' => 'Cannot abandon main planet'];
            }

            // Delete planet
            DB::table('planets')->where('id', $planetId)->delete();

            $this->botLog()->warning("Bot {$player->getId()} abandoned planet {$planetId}");

            return ['success' => true, 'planet_id' => $planetId];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
