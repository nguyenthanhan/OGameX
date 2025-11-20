<?php

namespace OGame\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OGame\Factories\PlanetServiceFactory;

/**
 * Process all game queues (buildings, research, units)
 * This job should run every minute to process completed items
 */
class ProcessGameQueuesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        //
    }

    public function handle(PlanetServiceFactory $planetFactory): void
    {
        $startTime = microtime(true);
        $processed = [
            'buildings' => 0,
            'research' => 0,
            'units' => 0,
        ];

        try {
            // Process building queues
            $processed['buildings'] = $this->processBuildingQueues($planetFactory);
            
            // Process research queues
            $processed['research'] = $this->processResearchQueues();
            
            // Process unit queues
            $processed['units'] = $this->processUnitQueues($planetFactory);
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            if ($processed['buildings'] > 0 || $processed['research'] > 0 || $processed['units'] > 0) {
                Log::info("ProcessGameQueuesJob completed in {$duration}ms", $processed);
            }
            
        } catch (\Exception $e) {
            Log::error("ProcessGameQueuesJob failed: " . $e->getMessage());
        }
    }

    /**
     * Process building queues for all planets
     */
    private function processBuildingQueues(PlanetServiceFactory $planetFactory): int
    {
        $processed = 0;
        
        // Get all planets with building queues (completed or waiting to start)
        $planets = DB::table('building_queues')
            ->select('planet_id')
            ->where('processed', 0)
            ->where(function($query) {
                // Either completed buildings or waiting buildings
                $query->where(function($q) {
                    $q->where('building', 1)->where('time_end', '<=', time());
                })->orWhere(function($q) {
                    $q->where('building', 0)->where('time_start', 0);
                });
            })
            ->distinct()
            ->pluck('planet_id');
        
        foreach ($planets as $planetId) {
            try {
                $planetModel = \OGame\Models\Planet::find($planetId);
                if (!$planetModel) {
                    continue;
                }
                
                $player = resolve(\OGame\Services\PlayerService::class, ['player_id' => $planetModel->user_id]);
                $planet = $planetFactory->makeFromModel($planetModel, $player);
                $planet->updateBuildingQueue();
                $processed++;
            } catch (\Exception $e) {
                Log::warning("Failed to process building queue for planet {$planetId}: " . $e->getMessage());
            }
        }
        
        return $processed;
    }

    /**
     * Process research queues
     */
    private function processResearchQueues(): int
    {
        $processed = 0;
        
        // Get all users with completed research (join with planets to get user_id)
        $users = DB::table('research_queues')
            ->join('planets', 'research_queues.planet_id', '=', 'planets.id')
            ->select('planets.user_id')
            ->where('research_queues.time_end', '<=', time())
            ->distinct()
            ->pluck('user_id');
        
        foreach ($users as $userId) {
            try {
                $player = resolve(\OGame\Services\PlayerService::class, ['player_id' => $userId]);
                $player->updateResearchQueue();
                $processed++;
            } catch (\Exception $e) {
                Log::warning("Failed to process research queue for user {$userId}: " . $e->getMessage());
            }
        }
        
        return $processed;
    }

    /**
     * Process unit queues
     */
    private function processUnitQueues(PlanetServiceFactory $planetFactory): int
    {
        $processed = 0;
        
        // Get all planets with completed unit queues
        $planets = DB::table('unit_queues')
            ->select('planet_id')
            ->where('time_end', '<=', time())
            ->distinct()
            ->pluck('planet_id');
        
        foreach ($planets as $planetId) {
            try {
                $planetModel = \OGame\Models\Planet::find($planetId);
                if (!$planetModel) {
                    continue;
                }
                
                $player = resolve(\OGame\Services\PlayerService::class, ['player_id' => $planetModel->user_id]);
                $planet = $planetFactory->makeFromModel($planetModel, $player);
                $planet->updateUnitQueue();
                $processed++;
            } catch (\Exception $e) {
                Log::warning("Failed to process unit queue for planet {$planetId}: " . $e->getMessage());
            }
        }
        
        return $processed;
    }
}
