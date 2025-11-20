@extends('ingame.layouts.main')

@section('content')
<div id="content">
    <div id="inhalt">
        <div class="contentBox01h">
            <h3 style="color: #0066cc; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; margin: 15px 0;">Create New Bot</h3>
        </div>

        @if($errors->any())
            <div style="background: #402d2d; border: 1px solid #7c4a4a; padding: 10px; margin: 10px 0; border-radius: 5px;">
                <ul style="margin: 0; padding-left: 20px;">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="contentBox01h" style="margin-top: 10px;">
            <form action="{{ route('admin.bots.store') }}" method="POST">
                @csrf

                <table style="width: 100%;">
                    <tr>
                        <td style="width: 200px; padding: 10px;">
                            <strong>Username:</strong><br>
                            <small style="color: #999;">Unique bot identifier</small>
                        </td>
                        <td style="padding: 10px;">
                            <input type="text" name="username" value="{{ old('username', $defaultUsername) }}" placeholder="e.g., bot-player-001" required style="width: 100%; padding: 5px;">
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 10px;">
                            <strong>Email:</strong><br>
                            <small style="color: #999;">Unique email address</small>
                        </td>
                        <td style="padding: 10px;">
                            <input type="email" name="email" value="{{ old('email', $defaultEmail) }}" placeholder="bot@example.com" required style="width: 100%; padding: 5px;">
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 10px;">
                            <strong>Password:</strong><br>
                            <small style="color: #999;">Minimum 6 characters</small>
                        </td>
                        <td style="padding: 10px; position: relative;">
                            <input type="password" id="password" name="password" value="{{ old('password', 'BotPassword123!') }}" placeholder="Enter a secure password" required style="width: calc(100% - 40px); padding: 5px; box-sizing: content-box;">
                            <span id="togglePassword" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #999; font-size: 18px;" onclick="togglePasswordVisibility()">👁️</span>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 10px;">
                            <strong>AI Provider & Model:</strong><br>
                            <small style="color: #999;">Required - Select provider and model</small>
                        </td>
                        <td style="padding: 10px;">
                            <select name="bot_ai_config_model" style="width: 100%; padding: 5px;" required>
                                @foreach($configs as $config)
                                    @if(is_array($config->bot_ai_model))
                                        @foreach($config->bot_ai_model as $model)
                                            <option value="{{ $config->id }}|{{ $model }}" 
                                                    {{ old('bot_ai_config_model') == $config->id.'|'.$model ? 'selected' : ($loop->parent->first && $loop->first && !old('bot_ai_config_model') ? 'selected' : '') }}>
                                                {{ $config->name }} - {{ $model }}
                                            </option>
                                        @endforeach
                                    @endif
                                @endforeach
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 10px;">
                            <strong>Backup AI Provider & Model:</strong><br>
                            <small style="color: #999;">Optional - Fallback if primary fails</small>
                        </td>
                        <td style="padding: 10px;">
                            <select name="backup_bot_ai_config_model" style="width: 100%; padding: 5px;">
                                <option value="">-- No Backup (Optional) --</option>
                                @foreach($configs as $config)
                                    @if(is_array($config->bot_ai_model))
                                        @foreach($config->bot_ai_model as $model)
                                            <option value="{{ $config->id }}|{{ $model }}" 
                                                    {{ old('backup_bot_ai_config_model') == $config->id.'|'.$model ? 'selected' : '' }}>
                                                {{ $config->name }} - {{ $model }}
                                            </option>
                                        @endforeach
                                    @endif
                                @endforeach
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 10px;">
                            <strong>Skill Level:</strong><br>
                            <small style="color: #999;">Bot expertise level</small>
                        </td>
                        <td style="padding: 10px;">
                            <select name="bot_skill_level" required style="width: 100%; padding: 5px;">
                                <option value="beginner" {{ old('bot_skill_level') == 'beginner' ? 'selected' : '' }}>Beginner</option>
                                <option value="intermediate" {{ old('bot_skill_level') == 'intermediate' ? 'selected' : 'selected' }}>Intermediate</option>
                                <option value="advanced" {{ old('bot_skill_level') == 'advanced' ? 'selected' : '' }}>Advanced</option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 10px;">
                            <strong>Strategy:</strong><br>
                            <small style="color: #999;">Bot behavior strategy</small>
                        </td>
                        <td style="padding: 10px;">
                            <select name="bot_strategy" required style="width: 100%; padding: 5px;">
                                <option value="aggressive" {{ old('bot_strategy') == 'aggressive' ? 'selected' : '' }}>Aggressive</option>
                                <option value="balanced" {{ old('bot_strategy') == 'balanced' ? 'selected' : 'selected' }}>Balanced</option>
                                <option value="defensive" {{ old('bot_strategy') == 'defensive' ? 'selected' : '' }}>Defensive</option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 10px;">
                            <strong>Status:</strong><br>
                            <small style="color: #999;">Enable or pause bot processing</small>
                        </td>
                        <td style="padding: 10px;">
                            <label>
                                <input type="checkbox" name="bot_enabled" value="1" {{ old('bot_enabled', true) ? 'checked' : '' }}>
                                ✅ Active (checked = bot is running)
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 10px;">
                            <strong>Notes:</strong><br>
                            <small style="color: #999;">Optional bot description</small>
                        </td>
                        <td style="padding: 10px;">
                            <input type="text" name="bot_notes" value="{{ old('bot_notes', 'Bot created for automated gameplay') }}" style="width: 100%; padding: 5px;" placeholder="Add any relevant notes about this bot...">
                        </td>
                    </tr>
                </table>

                <div class="footer">
                    <div class="textCenter">
                        <button type="submit" class="btn_blue">Create Bot</button>
                        <a href="{{ route('admin.bots.index') }}" class="btn_blue">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function togglePasswordVisibility() {
        const passwordInput = document.getElementById('password');
        const toggleButton = document.getElementById('togglePassword');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleButton.textContent = '🙈';
        } else {
            passwordInput.type = 'password';
            toggleButton.textContent = '👁️';
        }
    }

    // Handle AI config and model selection
    document.addEventListener('DOMContentLoaded', function() {
        const configSelect = document.getElementById('ai-config-select');
        const modelRow = document.getElementById('model-select-row');
        const modelSelect = document.getElementById('model-select');

        if (configSelect && modelRow && modelSelect) {
        configSelect.onchange = function() {
            const selectedOption = this.options[this.selectedIndex];
            const models = selectedOption.getAttribute('data-models');
            
            console.log('Config changed:', this.value, 'Models:', models);
            
            if (models && this.value) {
                try {
                    const modelArray = JSON.parse(models);
                    console.log('Parsed models:', modelArray);
                    
                    // Clear and populate model select
                    modelSelect.innerHTML = '';
                    modelSelect.disabled = false;
                    
                    modelArray.forEach((model, index) => {
                        const option = document.createElement('option');
                        option.value = model;
                        option.textContent = model;
                        if (index === 0) {
                            option.selected = true;
                        }
                        modelSelect.appendChild(option);
                    });
                    
                    // Force update by changing and resetting value
                    const firstValue = modelArray[0];
                    modelSelect.value = '';
                    setTimeout(() => {
                        modelSelect.value = firstValue;
                    }, 0);
                    
                    console.log('Model select enabled, first model selected');
                } catch (e) {
                    console.error('Error parsing models:', e);
                }
            } else {
                modelSelect.disabled = true;
                modelSelect.innerHTML = '<option value="">-- Select AI Configuration first --</option>';
                console.log('Model select disabled');
            }
        };

            // Trigger on page load if config is pre-selected
            if (configSelect.value) {
                console.log('Triggering change on load for config:', configSelect.value);
                if (configSelect.onchange) {
                    configSelect.onchange();
                }
            }
        } else {
            console.error('Model selection elements not found');
        }
    });
</script>
@endsection
