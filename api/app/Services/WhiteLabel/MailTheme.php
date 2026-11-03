<?php

namespace App\Services\WhiteLabel;

/**
 * The tones of the mail theme (resources/views/mail/default.blade.php): Laravel's default theme
 * draws its text, borders and panels in Tailwind's zinc scale (#18181b, #52525b, #71717a …),
 * whose blue cast stood out next to RunR's neutral palette (R = G = B, #645). The theme is
 * rendered with every zinc tone swapped for the neutral tone of the same shade; the brand
 * colours (links, the primary button) and the success / error buttons stay as they are.
 */
final class MailTheme
{
    /** Tailwind zinc → neutral, shade by shade (50 is the same in both). */
    public const NEUTRAL_TONES = [
        '#f4f4f5' => '#f5f5f5', // 100
        '#e4e4e7' => '#e5e5e5', // 200
        '#d4d4d8' => '#d4d4d4', // 300
        '#a1a1aa' => '#a3a3a3', // 400
        '#71717a' => '#737373', // 500
        '#52525b' => '#525252', // 600
        '#3f3f46' => '#404040', // 700
        '#27272a' => '#262626', // 800
        '#18181b' => '#171717', // 900
        '#09090b' => '#0a0a0a', // 950
    ];

    /** The CSS with the zinc tones replaced (any letter case). */
    public static function neutral(string $css): string
    {
        return (string) preg_replace_callback(
            '/#[0-9a-fA-F]{6}\b/',
            fn (array $m) => self::NEUTRAL_TONES[strtolower($m[0])] ?? $m[0],
            $css,
        );
    }
}
