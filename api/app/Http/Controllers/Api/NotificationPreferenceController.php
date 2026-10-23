<?php

namespace App\Http\Controllers\Api;

use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    // billing: region offers, fixed resource requests and their decisions (Billing module)
    // runtime_patch: runtime patches, end-of-life reminders (zero-day patches ignore it)
    // promotion: promotions between environments — one waits for your approval, yours was
    //            approved, rejected, deployed or failed (PromotionNotifier)
    private const TYPES = ['resource_threshold', 'build', 'deployment', 'infrastructure', 'demo', 'billing', 'runtime_patch', 'promotion'];

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
