@extends('ingame.layouts.main')

@section('content')
<div id="content">
    <div id="inhalt">
        <div class="contentBox01h">
            <h3 style="font-weight: 700; margin: 15px 10px;">Create Bot AI Config</h3>
        </div>

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
            <form action="{{ route('admin.bot-configs.store') }}" method="POST">
                @csrf

                <table style="width: 100%;">
                    <tr>
                        <td style="width: 200px; padding: 10px;">
                            <strong>Name:</strong><br>
                            <small style="color: #999;">Unique identifier</small>
                        </td>
                        <td style="padding: 10px;">
                            <input type="text" name="name" value="{{ old('name') }}" required style="width: 100%; padding: 5px;">
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 10px;">
                            <strong>Description:</strong><br>
                            <small style="color: #999;">Optional notes about quota, cost, etc.</small>
                        </td>
                        <td style="padding: 10px;">
                            <input type="text" name="description" value="{{ old('description') }}" style="width: 100%; padding: 5px;" placeholder="Optional notes about quota, cost, etc.">
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 10px;">
                            <strong>API URL:</strong><br>
                            <small style="color: #999;">
                                OpenAI: https://api.openai.com/v1<br>
                                Gemini: https://generativelanguage.googleapis.com<br>
                                Groq: https://api.groq.com/openai/v1
                            </small>
                        </td>
                        <td style="padding: 10px;">
                            <input type="url" name="bot_ai_url" value="{{ old('bot_ai_url') }}" required style="width: 100%; padding: 5px;" placeholder="https://generativelanguage.googleapis.com">
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 10px;">
                            <strong>Models:</strong><br>
                            <small style="color: #999;">
                                Comma-separated list<br>
                                Gemini: gemini-1.5-flash, gemini-1.5-pro<br>
                                OpenAI: gpt-4o-mini, gpt-4o<br>
                                Groq: llama3-70b-8192, mixtral-8x7b
                            </small>
                        </td>
                        <td style="padding: 10px;">
                            <input type="text" name="bot_ai_model" value="{{ old('bot_ai_model') }}" required style="width: 100%; padding: 5px;" placeholder="gemini-1.5-flash">
                            <small style="color: #999; display: block; margin-top: 5px;">Enter multiple models separated by commas. Bots will be able to choose from this list.</small>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 10px;">
                            <strong>API Key:</strong><br>
                            <small style="color: #999;">Will be encrypted at rest</small>
                        </td>
                        <td style="padding: 10px;">
                            <input type="password" name="bot_ai_api_key" value="{{ old('bot_ai_api_key') }}" required style="width: 100%; padding: 5px;">
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 10px;">
                            <strong>Status:</strong>
                        </td>
                        <td style="padding: 10px;">
                            <label>
                                <input type="checkbox" name="is_active" value="1" {{ old('is_active', true) ? 'checked' : '' }}>
                                Active (bots can use this config)
                            </label>
                        </td>
                    </tr>
                </table>

                <div class="footer">
                    <div class="textCenter">
                        <button type="submit" class="btn_blue">Create Config</button>
                        <a href="{{ route('admin.bot-configs.index') }}" class="btn_blue">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
