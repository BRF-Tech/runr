<?php

namespace App\Services\Ai\Engines;

/**
 * The weight files an engine loads from a repository listing, and their size: what model
 * services are sized from (EngineCatalog::sizing()).
 *
 * A Hub repository often carries one model in several formats — model.safetensors,
 * pytorch_model.bin, tf_model.h5, flax_model.msgpack, rust_model.ot, onnx/…, openvino/… — and the
 * engines load one of them. Counting every weight file would size a 268 MB model as if it had a
 * gigabyte. What is counted:
 *
 *  - the PyTorch engines (hf-serve's transformers pipelines, vLLM, TGI, TEI) load the weights in
 *    the repository root: safetensors, else a PyTorch checkpoint (.bin / .pt / .pth), else
 *    TensorFlow / Flax. With the transformers file names present (model.safetensors,
 *    model-00001-of-00002.safetensors, pytorch_model.bin …) only those count, so a second copy
 *    such as Mistral's consolidated.safetensors does not. Sentence-transformers module folders
 *    (1_Pooling/, 2_Dense/ …) are loaded too.
 *  - diffusers loads one checkpoint per component folder (unet/, vae/, text_encoder/ …) with
 *    the same preference, the plain one before variants such as .fp16.
 *  - a repository with no such weights counts what it has: ONNX (model.onnx with its external
 *    data, else the largest .onnx), GGUF (one file: the largest), else the preferred format
 *    in any folder.
 *
 * Training state (optimizer.pt, training_args.bin …) and OpenVINO .bin files are never weights.
 */
final class ModelWeights
{
    /** Formats in the order the PyTorch engines prefer them, with their file pattern. */
    private const FORMATS = [
        'safetensors' => '/\.safetensors$/',
        'pytorch' => '/\.(bin|pt|pth)$/',
        'tensorflow' => '/\.h5$/',
        'flax' => '/\.msgpack$/',
    ];

    /** One-model formats: counted when the repository has none of FORMATS. */
    private const ONNX = '/\.onnx$|\.onnx_data$|\.onnx\.data$/';

    private const GGUF = '/\.gguf$/';

    private const NOT_WEIGHTS = '#(^|/)(training_args|optimizer|scheduler|scaler|rng_state[^/]*)\.(bin|pt|pth)$|(^|/)openvino[^/]*\.bin$#i';

    /** File names transformers (and the engines built on it) look for. */
    private const STANDARD = '/^(model|pytorch_model|adapter_model|tf_model|flax_model)(-\d+-of-\d+)?\.(safetensors|bin|h5|msgpack)$/';

    private const STANDARD_DIFFUSERS = '/^(diffusion_pytorch_model|model)(-\d+-of-\d+)?\.(safetensors|bin)$/';

    /** Sentence-transformers module folders (1_Pooling, 2_Dense, 3_Normalize …). */
    private const ST_MODULE = '#^\d+_[^/]+/[^/]+$#';

    /**
     * @param  list<array{name: string, size?: ?int}>  $files  repository listing (Hub siblings)
     * @return array{format: ?string, files: list<string>, bytes: ?int}
     */
    public static function loaded(array $files, ?string $library = null): array
    {
        $weights = [];
        foreach ($files as $file) {
            $name = ltrim($file['name'], '/');
            if ($name === '' || preg_match(self::NOT_WEIGHTS, $name)) {
                continue;
            }
            $weights[$name] = isset($file['size']) ? (int) $file['size'] : null;
        }

        $diffusers = $library === 'diffusers' || in_array('model_index.json', array_column($files, 'name'), true);
        if ($diffusers && ($picked = self::diffusersComponents($weights))) {
            return self::result($picked['format'], $picked['files'], $weights);
        }

        $root = array_filter($weights, fn ($size, string $name) => ! str_contains($name, '/') || preg_match(self::ST_MODULE, $name), ARRAY_FILTER_USE_BOTH);
        foreach (self::FORMATS as $format => $pattern) {
            $matching = self::named($root, $pattern);
            if ($matching === []) {
                continue;
            }
            $rootFiles = array_values(array_filter($matching, fn (string $name) => ! str_contains($name, '/')));
            $standard = array_values(array_filter($rootFiles, fn (string $name) => (bool) preg_match(self::STANDARD, $name)));
            if ($standard !== []) {
                // the standard files plus module folders; another root copy (consolidated.*) is not loaded
                $matching = array_merge($standard, array_values(array_diff($matching, $rootFiles)));
            }

            return self::result($format, $matching, $weights);
        }

        $onnx = self::named($weights, self::ONNX);
        if ($onnx !== []) {
            $main = array_values(array_filter($onnx, fn (string $name) => (bool) preg_match('#^(onnx/)?model\.onnx(_data|\.data)?$#', $name)));

            return self::result('onnx', $main !== [] ? $main : [self::largest($onnx, $weights)], $weights);
        }

        $gguf = self::named($weights, self::GGUF);
        if ($gguf !== []) {
            return self::result('gguf', [self::largest($gguf, $weights)], $weights);
        }

        foreach (self::FORMATS + ['ckpt' => '/\.ckpt$/'] as $format => $pattern) {
            $matching = self::named($weights, $pattern);
            if ($matching !== []) {
                return self::result($format, $matching, $weights);
            }
        }

        return ['format' => null, 'files' => [], 'bytes' => null];
    }

    /** Bytes the engine loads; null when the listing has no weights or no sizes. */
    public static function bytes(array $files, ?string $library = null): ?int
    {
        return self::loaded($files, $library)['bytes'];
    }

    /**
     * One checkpoint per diffusers component folder: the preferred format, the plain files before
     * a variant (diffusion_pytorch_model.fp16.safetensors).
     *
     * @param  array<string, ?int>  $weights
     * @return array{format: string, files: list<string>}|null
     */
    private static function diffusersComponents(array $weights): ?array
    {
        $components = [];
        foreach (array_keys($weights) as $name) {
            if (substr_count($name, '/') === 1) {
                $components[explode('/', $name)[0]][] = $name;
            }
        }

        $picked = [];
        $formats = [];
        foreach ($components as $names) {
            foreach (self::FORMATS as $format => $pattern) {
                $matching = array_values(array_filter($names, fn (string $name) => (bool) preg_match($pattern, $name)));
                if ($matching === []) {
                    continue;
                }

                $plain = array_values(array_filter($matching, fn (string $name) => (bool) preg_match(self::STANDARD_DIFFUSERS, basename($name))));
                if ($plain === []) {
                    // only variants: the first one (name order) — one copy of the component
                    $variants = [];
                    foreach ($matching as $name) {
                        $variants[self::variant(basename($name))][] = $name;
                    }
                    ksort($variants);
                    $plain = reset($variants);
                }

                array_push($picked, ...$plain);
                $formats[$format] = true;
                break;
            }
        }

        if ($picked === []) {
            return null;
        }

        return ['format' => count($formats) === 1 ? (string) array_key_first($formats) : 'mixed', 'files' => $picked];
    }

    /** "diffusion_pytorch_model.fp16.safetensors" → "fp16"; "model.safetensors" → "". */
    private static function variant(string $basename): string
    {
        $parts = explode('.', $basename);

        return count($parts) > 2 ? $parts[count($parts) - 2] : '';
    }

    /**
     * @param  array<string, ?int>  $weights
     * @return list<string>
     */
    private static function named(array $weights, string $pattern): array
    {
        return array_values(array_filter(array_keys($weights), fn (string $name) => (bool) preg_match($pattern, $name)));
    }

    /** @param  list<string>  $names */
    private static function largest(array $names, array $weights): string
    {
        usort($names, fn (string $a, string $b) => ($weights[$b] ?? 0) <=> ($weights[$a] ?? 0) ?: strcmp($a, $b));

        return $names[0];
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, ?int>  $weights
     * @return array{format: ?string, files: list<string>, bytes: ?int}
     */
    private static function result(string $format, array $names, array $weights): array
    {
        sort($names);
        $known = array_filter(array_map(fn (string $name) => $weights[$name] ?? null, $names), fn ($size) => $size !== null);

        return [
            'format' => $format,
            'files' => $names,
            'bytes' => $known === [] ? null : (int) array_sum($known),
        ];
    }
}
