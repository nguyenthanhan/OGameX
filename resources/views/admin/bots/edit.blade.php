@extends('ingame.layouts.main')

@section('content')
<div id="content">
    <div id="inhalt">
        <div class="contentBox01h">
            <h3 style="color: #0066cc; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; margin: 15px 0;">Edit Bot: {{ $bot->username }}</h3>
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
            <form id="bot-edit-form" action="{{ route('admin.bots.update', $bot) }}" method="POST">
                @csrf
                @method('PUT')

                <table style="width: 100%;">
                    <tr>
                        <td style="width: 200px; padding: 10px;">
                            <strong>Username:</strong><br>
                            <small style="color: #999;">Bot identifier (read-only)</small>
                        </td>
                        <td style="padding: 10px;">
                            <input type="text" value="{{ $bot->username }}" disabled style="width: 100%; padding: 5px; background: #333; color: #999;">
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 10px;">
                            <strong>Email:</strong><br>
                            <small style="color: #999;">Email address (read-only)</small>
                        </td>
                        <td style="padding: 10px;">
                            <input type="email" value="{{ $bot->email }}" disabled style="width: 100%; padding: 5px; background: #333; color: #999;">
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
                                                    {{ ($bot->bot_ai_config_id == $config->id && $bot->bot_ai_model == $model) ? 'selected' : '' }}>
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
                                                    {{ ($bot->backup_bot_ai_config_id == $config->id && $bot->backup_bot_ai_model == $model) ? 'selected' : '' }}>
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
                                @php
                                    $skillLevelMap = [3 => 'beginner', 5 => 'intermediate', 8 => 'advanced'];
                                    $currentSkillLevel = $skillLevelMap[$bot->bot_skill_level] ?? 'intermediate';
                                @endphp
                                <option value="beginner" {{ $currentSkillLevel == 'beginner' ? 'selected' : '' }}>Beginner</option>
                                <option value="intermediate" {{ $currentSkillLevel == 'intermediate' ? 'selected' : '' }}>Intermediate</option>
                                <option value="advanced" {{ $currentSkillLevel == 'advanced' ? 'selected' : '' }}>Advanced</option>
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
                                <option value="aggressive" {{ $bot->bot_strategy == 'aggressive' ? 'selected' : '' }}>Aggressive</option>
                                <option value="balanced" {{ ($bot->bot_strategy == 'balanced' || !$bot->bot_strategy) ? 'selected' : '' }}>Balanced</option>
                                <option value="defensive" {{ $bot->bot_strategy == 'defensive' ? 'selected' : '' }}>Defensive</option>
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
                                <input type="checkbox" name="bot_enabled" value="1" {{ $bot->bot_enabled ? 'checked' : '' }}>
                                ✅ Active (checked = bot is running)
                            </label>
                            <div style="color: #999; font-size: 12px; margin-top: 5px;">
                                Current status: <strong>{{ $bot->bot_enabled ? '🤖 ACTIVE' : '⏸️ PAUSED' }}</strong>
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 10px;">
                            <strong>Notes:</strong><br>
                            <small style="color: #999;">Bot description or remarks</small>
                        </td>
                        <td style="padding: 10px;">
                            <input type="text" name="bot_notes" value="{{ $bot->bot_notes }}" style="width: 100%; padding: 5px;" placeholder="Add any relevant notes about this bot...">
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 10px;">
                            <strong>Info:</strong>
                        </td>
                        <td style="padding: 10px;">
                            <small style="color: #999;">
                                Created: {{ $bot->created_at->format('Y-m-d H:i') }}<br>
                                Last Heartbeat: {{ $bot->bot_last_heartbeat ? $bot->bot_last_heartbeat->format('Y-m-d H:i') : 'Never' }}
                            </small>
                        </td>
                    </tr>
                </table>

            </form>
            
            <div class="footer">
                <div class="textCenter">
                    <button type="submit" form="bot-edit-form" class="btn_blue">Update Bot</button>
                    <a href="{{ route('admin.bots.index') }}" class="btn_blue">Cancel</a>
                    
                    @php
                        $botService = app(\OGame\Services\Bot\BotService::class);
                        $fleetCheck = $botService->checkActiveFleets($bot->id);
                    @endphp
                    
                    @if($fleetCheck['has_fleets'])
                        <div style="display: inline-block; margin-left: 10px; padding: 8px 12px; background: #7c4a4a; border-radius: 3px; font-size: 12px;">
                            ⚠️ {{ $fleetCheck['count'] }} active fleet(s) - Cannot delete
                        </div>
                        <form id="force-delete-bot-form" action="{{ route('admin.bots.forceDestroy', $bot) }}" method="POST" style="display: inline-block; margin: 0;" onsubmit="return confirm('⚠️⚠️ FORCE DELETE BOT ⚠️⚠️\n\nThis bot has {{ $fleetCheck['count'] }} active fleet mission(s)!\n\nForce delete will:\n• CANCEL all active fleet missions\n• Delete bot account and all data\n• Delete all planets and buildings\n• Delete all decisions and logs\n• Delete all messages and notes\n\nThis action CANNOT be undone!\n\nAre you ABSOLUTELY SURE?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn_blue" style="background-color: #8b0000; border-color: #660000;">Force Delete (Cancel Fleets)</button>
                        </form>
                    @else
                        <form id="delete-bot-form" action="{{ route('admin.bots.destroy', $bot) }}" method="POST" style="display: inline-block; margin: 0;" onsubmit="return confirm('⚠️ DELETE BOT\n\nThis will permanently delete:\n• Bot account and all data\n• All planets and buildings\n• All decisions and error logs\n• All messages and notes\n\nThis action CANNOT be undone!\n\nContinue?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn_blue" style="background-color: #c0392b; border-color: #a93226;">Delete Bot</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="contentBox01h" style="margin-top: 40px; margin-bottom: 60px; background: #1a1a1a; border: 1px solid #7c4a4a;">
            <h3 style="color: #e74c3c; font-weight: 700; margin: 15px 10px;">⚠️ Dangerous Actions</h3>
            <div style="padding: 0 10px 15px 10px;">
                <p style="color: #999; margin: 0 0 15px 0; font-size: 13px;">These actions cannot be undone. Use with caution.</p>
                <form action="{{ route('admin.bots.resetState', $bot) }}" method="POST" style="display: inline;">
                    @csrf
                    <button type="submit" class="btn_blue" title="WARNING: This will permanently clear:\n• Internal bot state and memory\n• Processing locks (if bot is stuck)\n• Last heartbeat timestamp\n• Last action timestamp\n\nThe bot will start fresh and begin processing immediately on the next cycle." onclick="return confirm('⚠️ RESET BOT STATE\n\nThis action will permanently clear:\n\n1. Bot State - All internal memories, plans, and cached data\n2. Processing Lock - Release if bot is stuck\n3. Heartbeat - Clear last activity timestamp\n4. Action Timestamp - Allow immediate processing\n\nThe bot will behave as if it\'s new and start fresh.\n\nContinue?')">Reset Bot State</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    // Handle AI config and model selection
    document.addEventListener('DOMContentLoaded', function() {
        const configSelect = document.getElementById('ai-config-select');
        const modelRow = document.getElementById('model-select-row');
        const modelSelect = document.getElementById('model-select');
        const currentModel = '{{ $bot->bot_ai_model }}';

        if (configSelect && modelRow && modelSelect) {
        configSelect.onchange = function() {
            const selectedOption = this.options[this.selectedIndex];
            const models = selectedOption.getAttribute('data-models');
            
            console.log('Config changed:', this.value, 'Models:', models, 'Current:', currentModel);
            
            if (models && this.value) {
                try {
                    const modelArray = JSON.parse(models);
                    console.log('Parsed models:', modelArray);
                    
                    // Clear and populate model select
                    modelSelect.innerHTML = '';
                    modelSelect.disabled = false;
                    
                    let selectedValue = modelArray[0];
                    modelArray.forEach((model, index) => {
                        const option = document.createElement('option');
                        option.value = model;
                        option.textContent = model;
                        if (model === currentModel) {
                            option.selected = true;
                            selectedValue = model;
                        } else if (index === 0 && model !== currentModel) {
                            option.selected = true;
                        }
                        modelSelect.appendChild(option);
                    });
                    
                    // Force update by changing and resetting value
                    modelSelect.value = '';
                    setTimeout(() => {
                        modelSelect.value = selectedValue;
                    }, 0);
                    
                    console.log('Model select enabled');
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
