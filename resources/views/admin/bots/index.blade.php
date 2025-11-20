@extends('ingame.layouts.main')

@section('content')
<div id="content">
    <div id="inhalt">
        <div class="contentBox01h">
            <h3 style="color: #0066cc; letter-spacing: 0.5px; font-weight: 700; margin: 15px 10px;">Manage Bots</h3>
        </div>

        @if(session('error'))
            <div style="background: #402d2d; border: 1px solid #7c4a4a; padding: 10px; margin: 10px 0; border-radius: 5px;">
                {{ session('error') }}
            </div>
        @endif

        <div class="contentBox01h" style="margin-top: 10px;">
            <table class="tablesorter" style="width: 100%; table-layout: fixed; text-align: center;">
                <thead>
                    <tr>
                        <th style="width: 20%;">Username</th>
                        <th style="width: 20%;">AI Config</th>
                        <th style="width: 15%;">Skill Level</th>
                        <th style="width: 15%;">Strategy</th>
                        <th style="width: 15%;">Status</th>
                        <th style="width: 15%;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($bots as $bot)
                        <tr style="height: 50px;">
                            <td style="padding: 8px 0; text-align: center;"><strong>{{ $bot->username }}</strong></td>
                            <td style="padding: 8px 0; text-align: center;">{{ $bot->aiConfig->name ?? 'N/A' }}</td>
                            <td style="padding: 8px 0; text-align: center;">
                                <span style="background: #354a5d; padding: 3px 8px; border-radius: 3px;">
                                    @php
                                        $skillMap = [3 => 'Beginner', 5 => 'Intermediate', 8 => 'Advanced'];
                                        echo $skillMap[$bot->bot_skill_level] ?? 'N/A';
                                    @endphp
                                </span>
                            </td>
                            <td style="padding: 8px 0; text-align: center;">
                                <span style="background: #3d5a2d; padding: 3px 8px; border-radius: 3px;">
                                    {{ ucfirst($bot->bot_strategy ?? 'N/A') }}
                                </span>
                            </td>
                            <td style="padding: 8px 0; text-align: center;">
                                @if($bot->bot_enabled)
                                    <span style="color: #4a7c4a;">✓ Active</span>
                                @else
                                    <span style="color: #f39c12;">🏖️ On Vacation</span>
                                @endif
                            </td>
                            <td style="padding: 8px 0;">
                                <a href="{{ route('admin.bots.edit', $bot) }}" class="btn_blue" style="margin: 5px; width: 70px; text-align: center; display: inline-block; box-sizing: border-box; padding: 4px 6px !important;">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 20px;">
                                No bots found
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="footer" style="margin-top: 20px;">
            <div class="textCenter">
                <a href="{{ route('admin.bots.create') }}" class="btn_blue">+ Create New Bot</a>
            </div>
        </div>

        @if($bots->hasPages())
            <div style="margin-top: 20px;">
                {{ $bots->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
