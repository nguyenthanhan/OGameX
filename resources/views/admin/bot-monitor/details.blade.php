@extends('ingame.layouts.main')

@section('content')
<div id="content">
    <div id="inhalt" style="padding-bottom: 50px;">
        <!-- Header -->
        <div class="contentBox01h" style="margin-bottom: 10px;">
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px;">
                <h3 style="color: #6f9fc8; margin: 0;">Bot Monitor: {{ $bot->username }}</h3>
                <div>
                    <a href="{{ route('admin.bot-monitor.dashboard') }}" class="btn_blue" style="margin-right: 5px;">← Back</a>
                    <a href="{{ route('admin.bots.edit', $bot) }}" class="btn_blue">⚙ Settings</a>
                </div>
            </div>
        </div>

        <!-- Status Overview -->
        <div class="contentBox01h" style="margin-bottom: 10px;">
            <h3 style="padding: 5px 10px; margin: 0;">Status</h3>
        </div>
        <div class="contentBox01h">
            <table class="tablesorter" style="width: 100%;">
                <tbody>
                    <tr>
                        <th style="width: 16.66%; text-align: center; padding: 15px;">Bot Status</th>
                        <th style="width: 16.66%; text-align: center; padding: 15px;">Strategy</th>
                        <th style="width: 16.66%; text-align: center; padding: 15px;">Skill Level</th>
                        <th style="width: 16.66%; text-align: center; padding: 15px;">AI Provider</th>
                        <th style="width: 16.66%; text-align: center; padding: 15px;">AI Model</th>
                        <th style="width: 16.66%; text-align: center; padding: 15px;">Last Action</th>
                    </tr>
                    <tr>
                        <td style="text-align: center; padding: 15px;">
                            @if($bot->bot_enabled)
                                <span style="color: #7cc77c; font-weight: bold; font-size: 16px;">✓ Active</span>
                            @else
                                <span style="color: #c7a87c; font-size: 20px;">⏸ Paused</span>
                            @endif
                        </td>
                        <td style="text-align: center; padding: 15px;">
                            <span style="color: #c7c7c7; font-size: 16px;">{{ ucfirst($bot->bot_strategy) }}</span>
                        </td>
                        <td style="text-align: center; padding: 15px;">
                            <span style="color: #c7c7c7; font-size: 16px;">{{ $bot->bot_skill_level }}/10</span>
                        </td>
                        <td style="text-align: center; padding: 15px;">
                            <span style="color: #6f9fc8; font-size: 16px;">{{ $aiProvider ?? 'Unknown' }}</span>
                        </td>
                        <td style="text-align: center; padding: 15px;">
                            <span style="color: #999; font-size: 13px;">{{ $bot->bot_ai_model ?? 'Not set' }}</span>
                        </td>
                        <td style="text-align: center; padding: 15px;">
                            <span style="color: #999; font-size: 13px;">
                                {{ $bot->bot_last_action ? \Carbon\Carbon::parse($bot->bot_last_action)->diffForHumans() : 'Never' }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Performance Statistics -->
        <div class="contentBox01h" style="margin-top: 10px; margin-bottom: 10px;">
            <h3 style="padding: 5px 10px; margin: 0;">Performance</h3>
        </div>
        <div class="contentBox01h">
            <table class="tablesorter" style="width: 100%;">
                <tbody>
                    <!-- Today Row -->
                    <tr>
                        <th style="width: 25%; text-align: center; padding: 12px; background: #1a2a3a; color: #6f9fc8; font-size: 16px;" colspan="4">📅 Today</th>
                    </tr>
                    <tr>
                        <th style="width: 25%; text-align: center; padding: 12px;">Total Actions</th>
                        <th style="width: 25%; text-align: center; padding: 12px;">Successful</th>
                        <th style="width: 25%; text-align: center; padding: 12px;">Success Rate</th>
                        <th style="width: 25%; text-align: center; padding: 12px;">API Calls</th>
                    </tr>
                    <tr>
                        <td style="text-align: center; padding: 20px;">
                            <span style="color: #6f9fc8; font-size: 32px; font-weight: bold;">{{ $totalDecisionsToday }}</span>
                        </td>
                        <td style="text-align: center; padding: 20px;">
                            <span style="color: #7cc77c; font-size: 32px; font-weight: bold;">{{ $successfulDecisionsToday }}</span>
                        </td>
                        <td style="text-align: center; padding: 20px;">
                            @php
                                $rateColorToday = $successRateToday >= 80 ? '#7cc77c' : ($successRateToday >= 50 ? '#c7a87c' : '#c77c7c');
                            @endphp
                            <span style="color: {{ $rateColorToday }}; font-size: 32px; font-weight: bold;">
                                {{ round($successRateToday, 1) }}%
                            </span>
                        </td>
                        <td style="text-align: center; padding: 20px;">
                            <span style="color: #6f9fc8; font-size: 32px; font-weight: bold;">{{ $quotaUsageToday ?? 0 }}</span>
                        </td>
                    </tr>
                    
                    <!-- All Time Row -->
                    <tr>
                        <th style="width: 25%; text-align: center; padding: 12px; background: #1a2a3a; color: #6f9fc8; font-size: 16px; border-top: 2px solid #2a3a4a;" colspan="4">⏱️ All Time</th>
                    </tr>
                    <tr>
                        <th style="width: 25%; text-align: center; padding: 12px;">Total Actions</th>
                        <th style="width: 25%; text-align: center; padding: 12px;">Successful</th>
                        <th style="width: 25%; text-align: center; padding: 12px;">Success Rate</th>
                        <th style="width: 25%; text-align: center; padding: 12px;">API Calls</th>
                    </tr>
                    <tr>
                        <td style="text-align: center; padding: 20px;">
                            <span style="color: #6f9fc8; font-size: 32px; font-weight: bold;">{{ $totalDecisionsAll }}</span>
                        </td>
                        <td style="text-align: center; padding: 20px;">
                            <span style="color: #7cc77c; font-size: 32px; font-weight: bold;">{{ $successfulDecisionsAll }}</span>
                        </td>
                        <td style="text-align: center; padding: 20px;">
                            @php
                                $rateColorAll = $successRateAll >= 80 ? '#7cc77c' : ($successRateAll >= 50 ? '#c7a87c' : '#c77c7c');
                            @endphp
                            <span style="color: {{ $rateColorAll }}; font-size: 32px; font-weight: bold;">
                                {{ round($successRateAll, 1) }}%
                            </span>
                        </td>
                        <td style="text-align: center; padding: 20px;">
                            <span style="color: #6f9fc8; font-size: 32px; font-weight: bold;">{{ $quotaUsageAll ?? 0 }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Last Decision -->
        @if($lastDecision && $lastActions && $lastActions->count() > 0)
        <div class="contentBox01h" style="margin-top: 10px; margin-bottom: 10px;">
            <h3 style="padding: 5px 10px; margin: 0;">Last Decision ({{ $lastActions->count() }} actions)</h3>
            <div style="padding: 10px; background: #1a2a3a; border-top: 1px solid #2a3a4a;">
                <div style="margin-bottom: 8px;">
                    <span style="color: #6f9fc8; font-weight: bold; font-size: 11px; text-transform: uppercase;">Turn ID:</span>
                    <span style="color: #c7c7c7; font-size: 12px; font-family: monospace; margin-left: 8px;">{{ $lastDecision->turn_id }}</span>
                </div>
                <div style="margin-bottom: 8px;">
                    <span style="color: #6f9fc8; font-weight: bold; font-size: 11px; text-transform: uppercase;">Time:</span>
                    <span style="color: #c7c7c7; font-size: 12px; margin-left: 8px;">{{ \Carbon\Carbon::parse($lastDecision->created_at)->format('M d, Y H:i:s') }}</span>
                </div>
                <div>
                    <div style="color: #6f9fc8; font-weight: bold; font-size: 11px; text-transform: uppercase; margin-bottom: 4px;">Strategy:</div>
                    <div style="color: #c7c7c7; font-size: 12px; line-height: 1.5; padding-left: 8px; border-left: 2px solid #2a3a4a;">
                        {{ $lastDecision->overall_strategy ?? 'No strategy provided' }}
                    </div>
                </div>
            </div>
        </div>
        <div class="contentBox01h" style="margin-bottom: 30px;">
            <table class="tablesorter" style="width: 100%;">
                <tbody>
                    <tr>
                        <th style="width: 15%; text-align: center; padding: 12px;">Action</th>
                        <th style="width: 10%; text-align: center; padding: 12px;">Planet ID</th>
                        <th style="width: 20%; text-align: center; padding: 12px;">Target</th>
                        <th style="width: 10%; text-align: center; padding: 12px;">Quantity</th>
                        <th style="width: 15%; text-align: center; padding: 12px;">Result</th>
                        <th style="width: 30%; text-align: center; padding: 12px;">Error</th>
                    </tr>
                    @foreach($lastActions as $action)
                    <tr>
                        <td style="text-align: center; padding: 12px;">
                            <span style="color: #c7c7c7; font-size: 13px; font-weight: bold;">{{ $action->action_type }}</span>
                        </td>
                        <td style="text-align: center; padding: 12px;">
                            <span style="color: #6f9fc8; font-size: 13px;">{{ $action->planet_id ?? '-' }}</span>
                        </td>
                        <td style="text-align: center; padding: 12px;">
                            <span style="color: #6f9fc8; font-size: 13px;">{{ $action->target ?? 'N/A' }}</span>
                            @if($action->mission_type)
                                <br><small style="color: #999;">Mission: {{ $action->mission_type }}</small>
                            @endif
                        </td>
                        <td style="text-align: center; padding: 12px;">
                            <span style="color: #c7c7c7; font-size: 13px;">{{ $action->quantity ?? '-' }}</span>
                        </td>
                        <td style="text-align: center; padding: 12px;">
                            @php
                                $resultColor = $action->result === 'success' ? '#7cc77c' : ($action->result === 'pending' ? '#c7a87c' : '#c77c7c');
                            @endphp
                            <span style="color: {{ $resultColor }}; font-weight: bold; font-size: 13px;">
                                {{ ucfirst($action->result) }}
                            </span>
                        </td>
                        <td style="text-align: center; padding: 12px;">
                            <span style="color: #c77c7c; font-size: 12px;">
                                {{ $action->error_message ?? '-' }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @elseif($lastDecision)
        <div class="contentBox01h" style="margin-top: 10px; margin-bottom: 10px;">
            <h3 style="padding: 5px 10px; margin: 0;">Last Decision</h3>
        </div>
        <div class="contentBox01h" style="margin-bottom: 30px;">
            <table class="tablesorter" style="width: 100%;">
                <tbody>
                    <tr>
                        <th style="width: 20%; text-align: center; padding: 12px;">Time</th>
                        <th style="width: 20%; text-align: center; padding: 12px;">Action</th>
                        <th style="width: 20%; text-align: center; padding: 12px;">Target</th>
                        <th style="width: 20%; text-align: center; padding: 12px;">Result</th>
                        <th style="width: 20%; text-align: center; padding: 12px;">Error</th>
                    </tr>
                    <tr>
                        <td style="text-align: center; padding: 12px;">
                            <span style="color: #999; font-size: 13px;">
                                {{ \Carbon\Carbon::parse($lastDecision->created_at)->format('M d, H:i:s') }}
                            </span>
                        </td>
                        <td style="text-align: center; padding: 12px;">
                            <span style="color: #c7c7c7; font-size: 14px;">{{ $lastDecision->action_type }}</span>
                        </td>
                        <td style="text-align: center; padding: 12px;">
                            <span style="color: #6f9fc8; font-size: 14px;">{{ $lastDecision->target ?? 'N/A' }}</span>
                        </td>
                        <td style="text-align: center; padding: 12px;">
                            @php
                                $resultColor = $lastDecision->result === 'success' ? '#7cc77c' : '#c77c7c';
                            @endphp
                            <span style="color: {{ $resultColor }}; font-weight: bold; font-size: 14px;">
                                {{ ucfirst($lastDecision->result) }}
                            </span>
                        </td>
                        <td style="text-align: center; padding: 12px;">
                            <span style="color: #c77c7c; font-size: 12px;">
                                {{ $lastDecision->error_message ?? '-' }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        @endif

    </div>
</div>

<script>
    // Auto-refresh every 30 seconds
    setInterval(() => {
        location.reload();
    }, 30000);
</script>
@endsection
