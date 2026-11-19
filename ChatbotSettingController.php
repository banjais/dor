<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ConfigurationController;
use App\Models\ChatbotSetting;
use App\Models\ChatbotIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class ChatbotSettingController extends Controller
{
    private function ensureSuperAdmin(): void
    {
        abort_unless(
            Auth::check() && (int) Auth::user()->user_type_id === 2,
            403
        );
    }

    public function index()
    {
        $this->ensureSuperAdmin();

        $settings = ChatbotSetting::current();
        $integrations = ChatbotIntegration::query()
            ->get()
            ->keyBy('integration_code');
        $site_name = ConfigurationController::site_name();
        $abs_site_url = ConfigurationController::abs_site_url();

        return view('chatbot_settings.admin.index', [
            'settings' => $settings,
            'integrations' => $integrations,
            'hasApiKey' => filled($settings->api_key),
            'site_name' => $site_name,
            'abs_site_url' => $abs_site_url,
        ]);
    }

    public function testIntegration(Request $request)
    {
        $this->ensureSuperAdmin();

        $validated = $request->validate([
            'integration_code' => ['required', 'in:open_meteo,overpass,google_maps,openweathermap,tomtom,waze_partner,google_sheets,appsheet'],
        ]);

        $integration = ChatbotIntegration::where(
            'integration_code',
            $validated['integration_code']
        )->firstOrFail();

        if (! $integration->is_enabled) {
            return redirect()->route('admin.chatbot-settings.index')
                ->with('error', 'Enable and save this integration before testing.');
        }

        $failureReason = null;

        try {
            $client = Http::withUserAgent('DoR-Chatbot/1.0 (+https://dor.gov.np)')->connectTimeout(4)
                ->timeout(8)
                ->withoutRedirecting();

            if ($validated['integration_code'] === 'open_meteo') {
                $response = $client->acceptJson()->get(
                    'https://api.open-meteo.com/v1/forecast',
                    [
                        'latitude' => 27.7172,
                        'longitude' => 85.3240,
                        'current' => 'temperature_2m',
                    ]
                );

                if (! $response->successful() || ! is_array($response->json('current'))) {
                    throw new \RuntimeException('Connection test failed.');
                }
            } elseif ($validated['integration_code'] === 'overpass') {
                $response = $client->get('https://overpass-api.de/api/status');

                if (! $response->successful()) {
                    throw new \RuntimeException('Connection test failed.');
                }
            } elseif ($validated['integration_code'] === 'google_maps') {
                $apiKey = $integration->credentials['secret'] ?? null;
                if (! filled($apiKey)) {
                    throw new \RuntimeException('Google Maps API key is not configured.');
                }
                $response = $client->acceptJson()->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'address' => 'Kathmandu, Nepal', 'key' => $apiKey,
                ]);
                $status = $response->json('status', 'HTTP_' . $response->status());
                if (! $response->successful() || $status !== 'OK') {
                    $failureReason = 'Google Maps: ' . $status;
                    $message = strip_tags((string) $response->json('error_message', ''));
                    if ($message !== '') $failureReason .= ' — ' . mb_substr($message, 0, 220);
                    throw new \RuntimeException('Google Maps API test failed.');
                }
            } elseif ($validated['integration_code'] === 'openweathermap') {
                $apiKey = $integration->credentials['secret'] ?? null;
                if (! filled($apiKey)) throw new \RuntimeException('OpenWeatherMap API key is not configured.');
                $response = $client->acceptJson()->get('https://api.openweathermap.org/data/2.5/weather', [
                    'q' => 'Kathmandu,NP', 'appid' => $apiKey,
                ]);
                if (! $response->successful() || ! is_array($response->json('main'))) {
                    $failureReason = 'OpenWeatherMap: HTTP ' . $response->status() . ' — ' .
                        mb_substr((string) $response->json('message', 'API request failed'), 0, 180);
                    throw new \RuntimeException('OpenWeatherMap test failed.');
                }
            } elseif ($validated['integration_code'] === 'tomtom') {
                $apiKey = $integration->credentials['secret'] ?? null;
                if (! filled($apiKey)) throw new \RuntimeException('TomTom API key is not configured.');
                $response = $client->acceptJson()->get('https://api.tomtom.com/search/2/geocode/Kathmandu.json', [
                    'key' => $apiKey, 'limit' => 1,
                ]);
                if (! $response->successful() || ! is_array($response->json('results'))) {
                    $failureReason = 'TomTom: HTTP ' . $response->status();
                    throw new \RuntimeException('TomTom test failed.');
                }
            } elseif ($validated['integration_code'] === 'waze_partner') {
                $url = $integration->settings['url'] ?? null;
                if (! is_string($url) || ! str_starts_with($url, 'https://')) {
                    throw new \RuntimeException('Waze Partner HTTPS feed URL is not configured.');
                }
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                $ip = filter_var($host, FILTER_VALIDATE_IP);
                if (! $host || $ip || in_array($host, ['localhost'], true) ||
                    str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
                    throw new \RuntimeException('Waze feed URL host is not allowed.');
                }
                $response = $client->get($url);
                $body = trim($response->body());
                if (! $response->successful() || $body === '' ||
                    (! str_contains(strtolower($response->header('Content-Type', '')), 'json') &&
                     ! str_contains(strtolower($response->header('Content-Type', '')), 'xml'))) {
                    $failureReason = 'Waze Partner feed: HTTP ' . $response->status() . ' or unsupported feed format.';
                    throw new \RuntimeException('Waze feed test failed.');
                }
            } elseif ($validated['integration_code'] === 'google_sheets') {
                $url = $integration->settings['url'] ?? null;
                if (! is_string($url) || ! str_starts_with($url, 'https://') ||
                    ! preg_match('~docs\.google\.com/spreadsheets/d/.+/(export\?format=csv|pub\?output=csv)~i', $url)) {
                    throw new \RuntimeException('Google Sheets must be a publicly accessible CSV export URL.');
                }
                $response = $client->get($url);
                $body = trim($response->body());
                if (! $response->successful() || $body === '' ||
                    ! str_contains($body, ',') || str_contains(strtolower(substr($body, 0, 200)), '<html')) {
                    $failureReason = 'Google Sheets CSV: HTTP ' . $response->status() . ' or CSV data unavailable.';
                    throw new \RuntimeException('Google Sheets test failed.');
                }
            } else {
                $apiKey = $integration->credentials['secret'] ?? null;
                $appId = $integration->settings['app_id'] ?? null;
                $tableName = $integration->settings['table_name'] ?? null;
                if (! filled($apiKey) || ! filled($appId) || ! filled($tableName)) {
                    throw new \RuntimeException('AppSheet requires its Access Key, App ID and Table Name.');
                }
                $response = $client->acceptJson()->withHeaders([
                    'ApplicationAccessKey' => $apiKey,
                ])->post('https://api.appsheet.com/api/v2/apps/' . rawurlencode($appId) .
                    '/tables/' . rawurlencode($tableName) . '/Action', [
                        'Action' => 'Find',
                        'Properties' => ['Locale' => 'en-US', 'Timezone' => 'Asia/Kathmandu'],
                        'Rows' => [],
                    ]);
                if (! $response->successful()) {
                    $failureReason = 'AppSheet API: HTTP ' . $response->status();
                    throw new \RuntimeException('AppSheet API test failed.');
                }
            }

            $integration->forceFill([
                'health_status' => 'healthy',
                'last_tested_at' => now(),
                'last_success_at' => now(),
                'last_error' => null,
                'updated_by' => Auth::id(),
            ])->save();

            return redirect()->to(
                route('admin.chatbot-settings.index') . '#integration-' . $integration->integration_code
            );
        } catch (\Throwable) {
            $integration->forceFill([
                'health_status' => 'unavailable',
                'last_tested_at' => now(),
                'last_error' => $failureReason ?? 'Connection test failed. Check service availability and try again.',
                'updated_by' => Auth::id(),
            ])->save();

            return redirect()->to(
                route('admin.chatbot-settings.index') . '#integration-' . $integration->integration_code
            );
        }
    }

    public function update(Request $request)
    {
        $this->ensureSuperAdmin();
        \Log::info('CHATBOT_SAVE_DIAG: update reached');

        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'bot_name' => ['required', 'string', 'max:100'],
            'welcome_message' => ['nullable', 'string', 'max:2000'],
            'offline_message' => ['nullable', 'string', 'max:2000'],
            'response_language' => ['required', 'in:both,en,ne'],
            'position' => ['required', 'in:bottom_right,bottom_left'],
            'panel_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'header_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'user_message_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'bot_message_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'text_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'appsheet_url' => ['nullable', 'url', 'max:2048'],
            'google_sheet_url' => ['nullable', 'url', 'max:2048'],
            'integrations' => ['nullable', 'array'],
            'integrations.*.enabled' => ['nullable', 'boolean'],
            'integrations.*.credential' => ['nullable', 'string', 'max:5000'],
            'integrations.*.url' => ['nullable', 'url', 'max:2048'],
            'integrations.*.app_id' => ['nullable', 'string', 'max:200'],
            'integrations.*.table_name' => ['nullable', 'string', 'max:200'],
            'website_base_url' => ['required', 'url', 'max:2048'],
            'website_index_enabled' => ['nullable', 'boolean'],
            'internal_ai_enabled' => ['nullable', 'boolean'],
            'hardware_mode' => ['required', 'in:cpu,gpu,auto'],
            'gpu_enabled' => ['nullable', 'boolean'],
            'gpu_server_url' => ['nullable', 'url', 'max:2048'],
            'gpu_model' => ['nullable', 'string', 'max:100'],
            'supported_by_name' => ['nullable', 'string', 'max:150'],
            'supported_by_email' => ['nullable', 'email', 'max:254'],
            'supported_by_visible' => ['nullable', 'boolean'],
            'external_api_enabled' => ['nullable', 'boolean'],
            'primary_enabled' => ['nullable', 'boolean'],
            'primary_provider' => ['nullable', 'in:gemini,groq,cerebras,mistral,openai,azure,custom'],
            'primary_model' => ['nullable', 'string', 'max:150'],
            'primary_base_url' => ['nullable', 'url', 'max:2048'],
            'primary_api_key' => ['nullable', 'string', 'max:5000'],
            'clear_primary_api_key' => ['nullable', 'boolean'],

            'secondary_enabled' => ['nullable', 'boolean'],
            'secondary_provider' => ['nullable', 'in:gemini,groq,cerebras,mistral,openai,azure,custom'],
            'secondary_model' => ['nullable', 'string', 'max:150'],
            'secondary_base_url' => ['nullable', 'url', 'max:2048'],
            'secondary_api_key' => ['nullable', 'string', 'max:5000'],
            'clear_secondary_api_key' => ['nullable', 'boolean'],

            'fallback_enabled' => ['nullable', 'boolean'],
            'fallback_provider' => ['nullable', 'in:gemini,groq,cerebras,mistral,openai,azure,custom'],
            'fallback_model' => ['nullable', 'string', 'max:150'],
            'fallback_base_url' => ['nullable', 'url', 'max:2048'],
            'fallback_api_key' => ['nullable', 'string', 'max:5000'],
            'clear_fallback_api_key' => ['nullable', 'boolean'],

            'cache_enabled' => ['nullable', 'boolean'],
            'cache_exact_match_enabled' => ['nullable', 'boolean'],
            'cache_ttl_minutes' => ['required', 'integer', 'between:1,10080'],

            'api_key' => ['nullable', 'string', 'max:5000'],
            'clear_api_key' => ['nullable', 'boolean'],
            'voice_enabled' => ['nullable', 'boolean'],
            'speech_input_enabled' => ['nullable', 'boolean'],
            'voice_output_enabled' => ['nullable', 'boolean'],
            'voice_type' => ['required', 'in:male,female,custom'],
            'custom_voice' => ['nullable', 'string', 'max:100'],
            'voice_speed' => ['required', 'numeric', 'between:0.50,2.00'],
        ], [
            'required' => 'Please fill out this required field.',
            'response_language.required' => 'Please select a response language.',
            'response_language.in' => 'Please select a valid response language.',
            'position.required' => 'Please select a chat window position.',
            'position.in' => 'Please select a valid chat window position.',
            'ai_provider.required' => 'Please select an AI provider.',
            'ai_provider.in' => 'Please select a valid AI provider.',
            'api_format.required' => 'Please select an API format.',
            'hardware_mode.required' => 'Please select an AI hardware mode.',
            'hardware_mode.in' => 'Please select a valid hardware mode.',
            'voice_type.required' => 'Please select a voice type.',
            'voice_type.in' => 'Please select a valid voice type.',
            'panel_color.regex' => 'Please select a valid chat window background colour.',
            'header_color.regex' => 'Please select a valid header background colour.',
            'user_message_color.regex' => 'Please select a valid user message colour.',
            'bot_message_color.regex' => 'Please select a valid bot message colour.',
            'text_color.regex' => 'Please select a valid text colour.',
            'url' => 'Please enter a valid URL.',
            'supported_by_email.email' => 'Please enter a valid contact email address.',
            'cache_ttl_minutes.integer' => 'Please enter the cache duration as a whole number.',
            'cache_ttl_minutes.between' => 'Cache duration must be between 1 and 10080 minutes.',
            'voice_speed.numeric' => 'Please enter a valid voice speed.',
            'voice_speed.between' => 'Voice speed must be between 0.50 and 2.00.',
        ]);

\Log::info('CHATBOT_SAVE_DIAG: validation passed', ['has_integrations' => $request->has('integrations')]);
        $settings = ChatbotSetting::current();

        unset(
            $validated['api_key'],
            $validated['clear_api_key'],
            $validated['primary_api_key'],
            $validated['clear_primary_api_key'],
            $validated['secondary_api_key'],
            $validated['clear_secondary_api_key'],
            $validated['fallback_api_key'],
            $validated['clear_fallback_api_key']
        );

        foreach ([
            'enabled',
            'voice_enabled',
            'speech_input_enabled',
            'voice_output_enabled',
            'website_index_enabled',
            'internal_ai_enabled',
            'gpu_enabled',
            'supported_by_visible',
            'external_api_enabled',
            'primary_enabled',
            'secondary_enabled',
            'fallback_enabled',
            'cache_enabled',
            'cache_exact_match_enabled',
        ] as $field) {
            $validated[$field] = $request->boolean($field);
        }

        $validated['updated_by'] = Auth::id();

        if ($request->boolean('clear_api_key')) {
            $settings->api_key = null;
        } elseif ($request->filled('api_key')) {
            $settings->api_key = $request->input('api_key');
        }

        foreach (['primary', 'secondary', 'fallback'] as $slot) {
            $keyField = $slot . '_api_key';
            $clearField = 'clear_' . $keyField;

            if ($request->boolean($clearField)) {
                $settings->{$keyField} = null;
            } elseif ($request->filled($keyField)) {
                $settings->{$keyField} = $request->input($keyField);
            }
        }

        // Provider profiles are enforced server-side; browser values cannot
        // override the official Gemini endpoint or model.
        $providerProfiles = [
            'gemini' => [
                'model' => 'gemini-3.8-flash',
                'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
            ],
        ];

        foreach (['primary', 'secondary', 'fallback'] as $slot) {
            $provider = $validated[$slot . '_provider'] ?? null;
            $keyField = $slot . '_api_key';

            if ($provider === 'gemini') {
                $validated[$slot . '_model'] = $providerProfiles['gemini']['model'];
                $validated[$slot . '_base_url'] = $providerProfiles['gemini']['base_url'];
            }

            $hasSavedKey = filled($settings->{$keyField});
            $hasProviderConfig = $provider === 'gemini'
                || (
                    filled($validated[$slot . '_model'] ?? null)
                    && filled($validated[$slot . '_base_url'] ?? null)
                );

            // No key, no provider, or incomplete custom configuration = disabled.
            $validated[$slot . '_enabled'] =
                filled($provider) && $hasSavedKey && $hasProviderConfig;
        }

        unset($validated['integrations']);

        $settings->fill($validated);
        $settings->save();

        // Only these fixed integration profiles may be configured from this form.
        $integrationProfiles = [
            'open_meteo' => ['Open-Meteo', 'weather'],
            'overpass' => ['Overpass API', 'mapping'],
            'google_maps' => ['Google Maps', 'mapping'],
            'openweathermap' => ['OpenWeatherMap', 'weather'],
            'tomtom' => ['TomTom', 'mapping'],
            'waze_partner' => ['Waze for Cities Partner', 'traffic'],
            'google_sheets' => ['Google Sheets', 'directory'],
            'appsheet' => ['AppSheet Contact Directory', 'directory'],
        ];

\Log::info('CHATBOT_SAVE_DIAG: entering integration loop');
        foreach ($integrationProfiles as $code => [$displayName, $type]) {
            $input = $request->input('integrations.' . $code, []);
            $input = is_array($input) ? $input : [];

            $integration = ChatbotIntegration::firstOrNew([
                'integration_code' => $code,
            ]);

            $integration->display_name = $displayName;
            $integration->integration_type = $type;

            $credentials = $integration->credentials ?? [];
            if ($request->boolean('integrations.' . $code . '.clear_credential')) {
                $credentials = [];
            } elseif (filled($input['credential'] ?? null)) {
                $credentials['secret'] = $input['credential'];
            }
            $integration->credentials = $credentials ?: null;

            $integrationSettings = $integration->settings ?? [];
            $url = $input['url'] ?? null;

            if ($code === 'google_sheets') {
                $url = $validated['google_sheet_url'] ?? null;
            } elseif ($code === 'appsheet') {
                $url = $validated['appsheet_url'] ?? null;
            }

            if (filled($url)) {
                $integrationSettings['url'] = $url;
            } elseif (in_array($code, ['google_sheets', 'appsheet', 'waze_partner'], true)) {
                unset($integrationSettings['url']);
            }

            foreach (['app_id', 'table_name'] as $settingKey) {
                $settingValue = $input[$settingKey] ?? null;
                if ($code === 'appsheet' && filled($settingValue)) {
                    $integrationSettings[$settingKey] = $settingValue;
                } elseif ($code === 'appsheet') {
                    unset($integrationSettings[$settingKey]);
                }
            }

            $integration->settings = $integrationSettings ?: null;
            $integration->is_enabled = $request->boolean('integrations.' . $code . '.enabled');

            if ($integration->isDirty(['credentials', 'settings', 'is_enabled'])) {
                $integration->health_status = 'not_tested';
                $integration->last_error = null;
                $integration->last_tested_at = null;
            }

            $integration->updated_by = Auth::id();
            if (! $integration->exists) {
                $integration->created_by = Auth::id();
            }
            $integration->save();
        }

\Log::info('CHATBOT_SAVE_DIAG: integration loop completed', ['count' => ChatbotIntegration::count()]);

        return redirect()
            ->route('admin.chatbot-settings.index')
            ->with('success', 'Chatbot updated successfully.');
    }
}
