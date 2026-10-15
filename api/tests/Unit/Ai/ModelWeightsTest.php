<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Engines\EngineCatalog;
use App\Services\Ai\Engines\ModelWeights;
use PHPUnit\Framework\TestCase;

/**
 * Model services are sized from the weights an engine loads (one format, the transformers file
 * names, one checkpoint per diffusers component), not from every weight file of the repository.
 */
class ModelWeightsTest extends TestCase
{
    /** @param  array<string, int|null>  $files */
    private static function listing(array $files): array
    {
        return array_map(fn (string $name, ?int $size) => ['name' => $name, 'size' => $size], array_keys($files), array_values($files));
    }

    /** distilbert/distilbert-base-uncased-finetuned-sst-2-english: one 268 MB model in five formats. */
    private static function distilbert(): array
    {
        return self::listing([
            '.gitattributes' => 391,
            'README.md' => 8_000,
            'config.json' => 629,
            'map.jpeg' => 41_000,
            'model.safetensors' => 267_832_560,
            'onnx/added_tokens.json' => 2,
            'onnx/config.json' => 735,
            'onnx/model.onnx' => 267_955_144,
            'pytorch_model.bin' => 267_844_872,
            'rust_model.ot' => 267_846_324,
            'tf_model.h5' => 267_949_840,
            'tokenizer_config.json' => 48,
            'vocab.txt' => 231_508,
        ]);
    }

    public function test_one_format_of_a_repository_with_several_is_counted(): void
    {
        $weights = ModelWeights::loaded(self::distilbert(), 'transformers');

        $this->assertSame('safetensors', $weights['format']);
        $this->assertSame(['model.safetensors'], $weights['files']);
        $this->assertSame(267_832_560, $weights['bytes']);
    }

    public function test_the_memory_of_a_268_mb_model_follows_its_weights_not_the_repository(): void
    {
        $everyFormat = 267_832_560 + 267_955_144 + 267_844_872 + 267_949_840;
        $this->assertSame(3328, EngineCatalog::sizing('transformers', 'cpu', $everyFormat)['memory_mb'], 'what the whole repository asked for');

        $sizing = EngineCatalog::sizing('transformers', 'cpu', ModelWeights::bytes(self::distilbert(), 'transformers'));

        $this->assertSame(1536, $sizing['memory_mb'], '2.5 × 256 MiB + 768 MiB, rounded up to 256 MiB');
        $this->assertSame('1', $sizing['cpu']);
    }

    public function test_a_pytorch_checkpoint_counts_when_there_are_no_safetensors(): void
    {
        $weights = ModelWeights::loaded(self::listing([
            'pytorch_model.bin' => 500_000_000,
            'tf_model.h5' => 500_000_000,
            'flax_model.msgpack' => 500_000_000,
            'optimizer.pt' => 1_000_000_000,
            'training_args.bin' => 4_000,
            'scheduler.pt' => 1_000,
        ]));

        $this->assertSame('pytorch', $weights['format']);
        $this->assertSame(['pytorch_model.bin'], $weights['files'], 'training state is not a weight');
        $this->assertSame(500_000_000, $weights['bytes']);
    }

    public function test_sharded_weights_count_without_a_second_copy(): void
    {
        $weights = ModelWeights::loaded(self::listing([
            'consolidated.safetensors' => 14_500_000_000,
            'model-00001-of-00003.safetensors' => 4_900_000_000,
            'model-00002-of-00003.safetensors' => 5_000_000_000,
            'model-00003-of-00003.safetensors' => 4_600_000_000,
            'model.safetensors.index.json' => 24_000,
            'pytorch_model-00001-of-00002.bin' => 9_900_000_000,
            'pytorch_model-00002-of-00002.bin' => 4_600_000_000,
        ]));

        $this->assertSame('safetensors', $weights['format']);
        $this->assertSame(['model-00001-of-00003.safetensors', 'model-00002-of-00003.safetensors', 'model-00003-of-00003.safetensors'], $weights['files']);
        $this->assertSame(14_500_000_000, $weights['bytes']);
    }

    public function test_sentence_transformers_modules_are_loaded_with_the_model(): void
    {
        $weights = ModelWeights::loaded(self::listing([
            '1_Pooling/config.json' => 200,
            '2_Dense/config.json' => 100,
            '2_Dense/model.safetensors' => 1_600_000,
            '2_Dense/pytorch_model.bin' => 1_600_000,
            'model.safetensors' => 438_000_000,
            'onnx/model.onnx' => 438_000_000,
            'openvino/openvino_model.bin' => 438_000_000,
            'pytorch_model.bin' => 438_000_000,
        ]), 'sentence-transformers');

        $this->assertSame(['2_Dense/model.safetensors', 'model.safetensors'], $weights['files']);
        $this->assertSame(439_600_000, $weights['bytes']);
    }

    public function test_diffusers_loads_one_checkpoint_per_component(): void
    {
        $listing = self::listing([
            'model_index.json' => 600,
            'v1-5-pruned.ckpt' => 7_700_000_000,
            'v1-5-pruned.safetensors' => 7_700_000_000,
            'v1-5-pruned-emaonly.safetensors' => 4_270_000_000,
            'safety_checker/model.safetensors' => 1_216_000_000,
            'safety_checker/model.fp16.safetensors' => 608_000_000,
            'safety_checker/pytorch_model.bin' => 1_216_000_000,
            'text_encoder/model.safetensors' => 492_000_000,
            'text_encoder/model.fp16.safetensors' => 246_000_000,
            'text_encoder/pytorch_model.bin' => 492_000_000,
            'tokenizer/vocab.json' => 1_000_000,
            'unet/diffusion_pytorch_model.safetensors' => 3_440_000_000,
            'unet/diffusion_pytorch_model.fp16.safetensors' => 1_720_000_000,
            'unet/diffusion_pytorch_model.bin' => 3_440_000_000,
            'unet/diffusion_pytorch_model.non_ema.bin' => 3_440_000_000,
            'vae/diffusion_pytorch_model.safetensors' => 335_000_000,
            'vae/diffusion_pytorch_model.bin' => 335_000_000,
        ]);

        $weights = ModelWeights::loaded($listing, 'diffusers');

        $this->assertSame('safetensors', $weights['format']);
        $this->assertSame([
            'safety_checker/model.safetensors',
            'text_encoder/model.safetensors',
            'unet/diffusion_pytorch_model.safetensors',
            'vae/diffusion_pytorch_model.safetensors',
        ], $weights['files']);
        $this->assertSame(5_483_000_000, $weights['bytes']);
        $this->assertSame($weights, ModelWeights::loaded($listing), 'model_index.json marks a diffusers repository too');
    }

    public function test_diffusers_components_with_variants_only_count_one_of_them(): void
    {
        $weights = ModelWeights::loaded(self::listing([
            'model_index.json' => 600,
            'unet/diffusion_pytorch_model.fp16.safetensors' => 1_720_000_000,
            'unet/diffusion_pytorch_model.bf16.safetensors' => 1_720_000_000,
            'vae/diffusion_pytorch_model.bin' => 335_000_000,
        ]), 'diffusers');

        $this->assertSame('mixed', $weights['format']);
        $this->assertSame(['unet/diffusion_pytorch_model.bf16.safetensors', 'vae/diffusion_pytorch_model.bin'], $weights['files']);
        $this->assertSame(2_055_000_000, $weights['bytes']);
    }

    public function test_onnx_and_gguf_repositories_count_one_model(): void
    {
        $onnx = ModelWeights::loaded(self::listing([
            'onnx/model.onnx' => 133_000_000,
            'onnx/model_fp16.onnx' => 66_000_000,
            'onnx/model_quantized.onnx' => 34_000_000,
        ]));
        $this->assertSame(['format' => 'onnx', 'files' => ['onnx/model.onnx'], 'bytes' => 133_000_000], $onnx);

        $external = ModelWeights::loaded(self::listing(['model.onnx' => 1_000_000, 'model.onnx_data' => 2_000_000_000, 'decoder.onnx' => 900_000_000]));
        $this->assertSame(['model.onnx', 'model.onnx_data'], $external['files'], 'the model and its external data');

        $unnamed = ModelWeights::loaded(self::listing(['encoder.onnx' => 100, 'decoder.onnx' => 300]));
        $this->assertSame(['decoder.onnx'], $unnamed['files'], 'the largest');

        $gguf = ModelWeights::loaded(self::listing([
            'qwen2.5-0.5b-instruct-q4_k_m.gguf' => 491_000_000,
            'qwen2.5-0.5b-instruct-q8_0.gguf' => 676_000_000,
            'qwen2.5-0.5b-instruct-fp16.gguf' => 1_270_000_000,
        ]));
        $this->assertSame(['format' => 'gguf', 'files' => ['qwen2.5-0.5b-instruct-fp16.gguf'], 'bytes' => 1_270_000_000], $gguf);
    }

    public function test_weights_outside_the_root_count_when_the_root_has_none(): void
    {
        $weights = ModelWeights::loaded(self::listing([
            'checkpoint/model.safetensors' => 300,
            'checkpoint/pytorch_model.bin' => 300,
            'original/consolidated.00.pth' => 500,
        ]));

        $this->assertSame(['format' => 'safetensors', 'files' => ['checkpoint/model.safetensors'], 'bytes' => 300], $weights);
        $this->assertSame('ckpt', ModelWeights::loaded(self::listing(['sd/model.ckpt' => 9]))['format']);
    }

    public function test_listings_without_weights_or_sizes(): void
    {
        $this->assertSame(['format' => null, 'files' => [], 'bytes' => null], ModelWeights::loaded(self::listing(['README.md' => 10, 'config.json' => 5])));
        $this->assertSame(['format' => null, 'files' => [], 'bytes' => null], ModelWeights::loaded([]));
        $this->assertNull(ModelWeights::bytes(self::listing(['model.safetensors' => null])), 'the Hub gave no sizes');
        $this->assertSame(7, ModelWeights::bytes(self::listing(['model-00001-of-00002.safetensors' => 7, 'model-00002-of-00002.safetensors' => null])), 'the known part');
    }
}
