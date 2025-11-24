@extends('ingame.layouts.main')

@section('content')
<div id="content">
    <div id="inhalt">
        <div class="contentBox01h">
            <div style="display: flex; justify-content: space-between; align-items: center; margin: 8px 0;">
                <h3 style="color: #0066cc; letter-spacing: 0.5px; font-weight: 700; margin: 0;">🤖 Bot Activity Monitor</h3>
                <a href="{{ route('admin.bots.create') }}" class="btn_blue">+ Create Bot</a>
            </div>
        </div>

        @if(session('error'))
            <div style="background: #402d2d; border: 1px solid #7c4a4a; padding: 8px; margin: 8px 0; border-radius: 5px;">
                {{ session('error') }}
            </div>
        @endif

        <div class="contentBox01h" style="margin-top: 8px;">
            <table class="tablesorter" style="width: 100%; table-layout: fixed; text-align: center;">
                <thead>
                    <tr>
                        <th style="width: 22%;">Bot Name</th>
                        <th style="width: 18%;">Status</th>
                        <th style="width: 20%;">Last Decision</th>
                        <th style="width: 20%;">Success Rate</th>
                        <th style="width: 20%;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($bots as $bot)
                        <tr style="height: 40px;">
                            <!-- Bot Name -->
                            <td style="padding: 5px 0; text-align: center;">
                                <strong>{{ $bot->username }}</strong><br>
                                <small style="color: #999;">{{ $bot->aiConfig->name ?? 'Miner Mode' }}</small>
                            </td>

                            <!-- Status -->
                            <td style="padding: 5px 0;">
                                <span style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%;">
                                    @if($bot->bot_enabled)
                                        <span style="color: #4a7c4a; font-weight: bold;">✓ Active</span>
                                    @else
                                        <span style="color: #f39c12;">🏖️ On Vacation</span>
                                    @endif
                                    @if($bot->bot_processing_until && $bot->bot_processing_until > now())
                                        <small style="color: #f39c12; margin-top: 2px;">🔒 Processing</small>
                                    @endif
                                </span>
                            </td>

                            <!-- Last Decision -->
                            <td style="padding: 5px 0; font-size: 12px;">
                                @if($bot->last_decision)
                                    <strong>{{ $bot->last_decision->action_type }}</strong><br>
                                    <small style="color: #999;">{{ \Carbon\Carbon::parse($bot->last_decision->created_at)->diffForHumans() }}</small><br>
                                    <small style="color: {{ $bot->last_decision->result === 'success' ? '#4a7c4a' : '#7c4a4a' }};">
                                        {{ ucfirst($bot->last_decision->result) }}
                                    </small>
                                @else
                                    <small style="color: #999;">No decisions yet</small>
                                @endif
                            </td>

                            <!-- Success Rate -->
                            <td style="padding: 5px 0;">
                                @if($bot->total_recent > 0)
                                    <strong>{{ $bot->success_count }}/{{ $bot->total_recent }}</strong><br>
                                    <small style="color: #999;">
                                        {{ round(($bot->success_count / $bot->total_recent) * 100) }}%
                                    </small>
                                @else
                                    <small style="color: #999;">N/A</small>
                                @endif
                            </td>

                            <!-- Actions -->
                            <td style="padding: 5px 0;">
                                <a href="{{ route('admin.bot-monitor.details', $bot) }}" class="btn_blue" style="margin: 3px; width: 80px; text-align: center; display: inline-block; box-sizing: border-box; padding: 4px 6px !important; font-size: 11px;">Details</a>
                                <a href="{{ route('admin.bots.edit', $bot) }}" class="btn_blue" style="margin: 3px; width: 80px; text-align: center; display: inline-block; box-sizing: border-box; padding: 4px 6px !important; font-size: 11px;">Settings</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 15px;">
                                No bots found. <a href="{{ route('admin.bots.create') }}">Create one</a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    // Auto-refresh dashboard every 1 minute
    setInterval(() => {
        location.reload();
    }, 60000);
</script>
@endsection
