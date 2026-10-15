<?php

namespace App\Models;

use App\Casts\Encrypted;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The organization's Hugging Face access token (gated and private models). Stored encrypted
 * (APP_KEY); it reaches the regions only as the Kubernetes Secret `runr-hf-token` that model
 * pods and mirror Jobs read HF_TOKEN from (secretKeyRef, never a plain env value). Never
 * returned by the API — only its last characters.
 *
 * @property string $id
 * @property string $provider
 * @property string $token
 * @property string|null $hint
 * @property string|null $updated_by
 */
class AiHubCredential extends Model
{
    use HasUlids;

    public const HUGGINGFACE = 'huggingface';

    protected $fillable = ['provider', 'token', 'hint', 'updated_by'];

    protected $hidden = ['token'];

    protected $casts = [
        'token' => Encrypted::class, // secret at rest (docs/security.md, App\Support\Secrets\SecretColumns)
    ];

    public static function huggingFace(): ?self
    {
        return static::where('provider', self::HUGGINGFACE)->first();
    }

    public static function hintFor(string $token): string
    {
        return '…'.substr($token, -4);
    }
}
