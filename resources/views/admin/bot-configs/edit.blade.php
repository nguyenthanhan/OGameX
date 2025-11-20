@extends('ingame.layouts.main')

@section('content')
<div id="content">
    <div id="inhalt">
        <div class="contentBox01h">
            <h3 style="color: #0066cc; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; margin: 15px 0;">Edit Bot Config</h3>
        </div>

        @if($botsCount > 0)
            <div style="background: #40392d; border: 1px solid #7c6a4a; padding: 10px; margin: 10px 0; border-radius: 5px;">
                ⚠️ Warning: {{ $botsCount }} bot(s) are using this configuration
            </div>
        @endif

        @if($errors->any())
            <div id="toast-error" style="background: #402d2d; border: 1px solid #7c4a4a; padding: 10px; margin: 10px 0; border-radius: 5px; transition: opacity 0.3s ease;">
                <ul style="margin: 0; padding-left: 20px;">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>

            <script>
                // Auto-hide error toast after 8 seconds (longer for errors since there are multiple lines)
                const errorElement = document.getElementById('toast-error');
                if (errorElement) {
                    setTimeout(() => {
                        errorElement.style.opacity = '0';
                        errorElement.style.pointerEvents = 'none';
                        setTimeout(() => {
                            errorElement.remove();
                        }, 300);
                    }, 8000);
                }
            </script>
        @endif

        <div class="contentBox01h" style="margin-top: 10px;">
            <form action="{{ route('admin.bot-configs.update', $botAiConfig) }}" method="POST">
                @csrf
                @method('PUT')

                <table style="width: 100%;">
                    <tr>
                        <td style="width: 200px; padding: 10px;">
                            <strong>Name:</strong><br>
                            <small style="color: #999;">Unique identifier</small>
                        </td>
                        <td style="padding: 10px;">
                            <input type="text" name="name" value="{{ old('name', $botAiConfig->name) }}" required style="width: 100%; padding: 5px;">
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 10px;">
                            <strong>Description:</strong><br>
                            <small style="color: #999;">Optional notes</small>
                        </td>
                        <td style="padding: 10px;">
                            <input type="text" name="description" value="{{ old('description', $botAiConfig->description) }}" style="width: 100%; padding: 5px;" placeholder="e.g., Free tier, 100 requests/day">
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 10px;">
                            <strong>API URL:</strong>
                        </td>
                        <td style="padding: 10px;">
                            <input type="url" name="bot_ai_url" value="{{ old('bot_ai_url', $botAiConfig->bot_ai_url) }}" required style="width: 100%; padding: 5px;">
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 10px;">
                            <strong>Models:</strong><br>
                            <small style="color: #999;">Comma-separated list</small>
                        </td>
                        <td style="padding: 10px;">
                            <input type="text" name="bot_ai_model" value="{{ old('bot_ai_model', is_array($botAiConfig->bot_ai_model) ? implode(', ', $botAiConfig->bot_ai_model) : $botAiConfig->bot_ai_model) }}" required style="width: 100%; padding: 5px;" placeholder="model1, model2, model3">
                            <small style="color: #999; display: block; margin-top: 5px;">Enter multiple models separated by commas. Bots will be able to choose from this list.</small>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 10px;">
                            <strong>API Key:</strong><br>
                            <small style="color: #999;">Leave blank to keep current key</small>
                        </td>
                        <td style="padding: 10px;">
                            <input type="password" name="bot_ai_api_key" value="" placeholder="••••••••••••••••" style="width: 100%; padding: 5px;">
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 10px;">
                            <strong>Status:</strong>
                        </td>
                        <td style="padding: 10px;">
                            <label>
                                <input type="checkbox" name="is_active" value="1" {{ old('is_active', $botAiConfig->is_active) ? 'checked' : '' }}>
                                Active (bots can use this config)
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 10px;">
                            <strong>Info:</strong>
                        </td>
                        <td style="padding: 10px;">
                            <small style="color: #999;">
                                Created by: {{ $botAiConfig->creator->username ?? 'N/A' }}<br>
                                Created at: {{ $botAiConfig->created_at->format('Y-m-d H:i') }}
                            </small>
                        </td>
                    </tr>
                </table>

                <div class="footer">
                    <div class="textCenter">
                        <button type="submit" class="btn_blue">Update Config</button>
                        <a href="{{ route('admin.bot-configs.index') }}" class="btn_blue">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
