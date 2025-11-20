@extends('ingame.layouts.main')

@section('content')
<div id="content">
    <div id="inhalt">
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
                    <tr>
                        <th style="width: 25%; text-align: center; padding: 15px;">Total Actions</th>
                        <th style="width: 25%; text-align: center; padding: 15px;">Successful</th>
                        <th style="width: 25%; text-align: center; padding: 15px;">Success Rate</th>
                        <th style="width: 25%; text-align: center; padding: 15px;">API Calls Today</th>
                    </tr>
                    <tr>
                        <td style="text-align: center; padding: 20px;">
                            <span style="color: #6f9fc8; font-size: 36px; font-weight: bold;">{{ $totalDecisions }}</span>
                        </td>
                        <td style="text-align: center; padding: 20px;">
                            <span style="color: #7cc77c; font-size: 36px; font-weight: bold;">{{ $successfulDecisions }}</span>
                        </td>
                        <td style="text-align: center; padding: 20px;">
                            @php
                                $rateColor = $successRate >= 80 ? '#7cc77c' : ($successRate >= 50 ? '#c7a87c' : '#c77c7c');
                            @endphp
                            <span style="color: {{ $rateColor }}; font-size: 36px; font-weight: bold;">
                                {{ round($successRate, 1) }}%
                            </span>
                        </td>
                        <td style="text-align: center; padding: 20px;">
                            <span style="color: #6f9fc8; font-size: 36px; font-weight: bold;">{{ $quotaUsageToday ?? 0 }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Last Decision -->
        @if($lastDecision)
        <div class="contentBox01h" style="margin-top: 10px; margin-bottom: 10px;">
            <h3 style="padding: 5px 10px; margin: 0;">Last Decision</h3>
        </div>
        <div class="contentBox01h">
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
