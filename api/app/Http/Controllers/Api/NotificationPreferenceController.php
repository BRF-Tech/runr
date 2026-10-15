<?php

namespace App\Http\Controllers\Api;

use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    private const TYPES = ['resource_threshold', 'build', 'deployment', 'infrastructure', 'demo'];

    private const CHANNELS = ['in_app', 'email'];

    public function index(Request $request): JsonResponse
    {
        $existing = $request->user()
            ->notificationPreferences()
            ->get()
            ->keyBy(fn ($p) => "{$p->type}.{$p->channel}");

        $preferences = [];
        foreach (self::TYPES as $type) {
            foreach (self::CHANNELS as $channel) {
                $key = "{$type}.{$channel}";
                $enabled = $existing->has($key) ? $existing[$key]->enabled : true;
                $preferences[] = compact('type', 'channel', 'enabled');
            }
        }

        return response()->json(['preferences' => $preferences]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'preferences' => 'required|array',
            'preferences.*.type' => 'required|string|in:'.implode(',', self::TYPES),
            'preferences.*.channel' => 'required|string|in:'.implode(',', self::CHANNELS),
            'preferences.*.enabled' => 'required|boolean',
        ]);

        $userId = $request->user()->id;

        foreach ($request->input('preferences') as $pref) {
            NotificationPreference::updateOrCreate(
                ['user_id' => $userId, 'type' => $pref['type'], 'channel' => $pref['channel']],
                ['enabled' => $pref['enabled']],
            );
        }

        return response()->json(['message' => __('api.account.preferences_updated')]);
    }
}
