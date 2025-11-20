@extends('ingame.layouts.main')

@section('content')
<div id="content">
    <div id="inhalt">
        <div class="contentBox01h">
            <div style="display: flex; justify-content: space-between; align-items: center; margin: 15px 10px;">
                <h3 style="color: #0066cc; letter-spacing: 0.5px; font-weight: 700; margin: 0;">AI Providers</h3>
                <a href="{{ route('admin.bot-configs.create') }}" class="btn_blue">+ Add Config</a>
            </div>
        </div>

        @if(session('error'))
            <div id="toast-error" style="background: #402d2d; border: 1px solid #7c4a4a; padding: 10px; margin: 10px 0; border-radius: 5px; transition: opacity 0.3s ease;">
                {{ session('error') }}
            </div>
        @endif

        <script>
            // Auto-hide error toast after 5 seconds
            const errorElement = document.getElementById('toast-error');
            if (errorElement) {
                setTimeout(() => {
                    errorElement.style.opacity = '0';
                    errorElement.style.pointerEvents = 'none';
                    setTimeout(() => {
                        errorElement.remove();
                    }, 300);
                }, 5000);
            }
        </script>

        <div class="contentBox01h" style="margin-top: 10px; margin-bottom: 60px;">
            <table class="tablesorter" style="width: 100%; table-layout: fixed; text-align: center;">
                <thead>
                    <tr>
                        <th style="width: 25%;">Name</th>
                        <th style="width: 25%;">Model</th>
                        <th style="width: 15%;">Status</th>
                        <th style="width: 15%;">Bots Count</th>
                        <th style="width: 20%;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($configs as $config)
                        <tr style="height: 50px;">
                            <td style="padding: 8px 0; text-align: center;"><strong>{{ $config->name }}</strong></td>
                            <td style="padding: 8px 0; text-align: center;">
                                @if(is_array($config->bot_ai_model))
                                    {{ count($config->bot_ai_model) }} model(s)
                                    <br><small style="color: #999;">{{ implode(', ', array_slice($config->bot_ai_model, 0, 2)) }}{{ count($config->bot_ai_model) > 2 ? '...' : '' }}</small>
                                @else
                                    {{ $config->bot_ai_model }}
                                @endif
                            </td>
                            <td style="padding: 8px 0; text-align: center;">
                                @if($config->is_active)
                                    <span style="color: #4a7c4a;">✓ Active</span>
                                @else
                                    <span style="color: #7c4a4a;">✗ Inactive</span>
                                @endif
                            </td>
                            <td style="padding: 8px 0; text-align: center;">{{ $config->bots_count }}</td>
                            <td style="padding: 8px 0;">
                                <a href="{{ route('admin.bot-configs.edit', $config) }}" class="btn_blue" style="margin: 3px; width: 70px; text-align: center; display: inline-block; box-sizing: border-box; padding: 4px 6px !important; font-size: 11px;">Edit</a>
                                <form action="{{ route('admin.bot-configs.duplicate', $config) }}" method="POST" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="btn_blue" style="margin: 3px; width: 70px; box-sizing: border-box; padding: 4px 6px !important; font-size: 11px;">Duplicate</button>
                                </form>
                                @if($config->bots_count == 0)
                                    <form action="{{ route('admin.bot-configs.destroy', $config) }}" method="POST" style="display:inline;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn_blue" onclick="return confirm('Delete this config?')" style="margin: 3px; width: 70px; box-sizing: border-box; padding: 4px 6px !important; font-size: 11px;">Delete</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 20px;">
                                No AI configurations found. <a href="{{ route('admin.bot-configs.create') }}">Create one</a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($configs->hasPages())
            <div style="margin-top: 20px; margin-bottom: 60px;">
                {{ $configs->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
