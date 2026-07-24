<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Client for our self-hosted ComfyUI GPU box (docker/comfyui/) — SDXL-Turbo
 * images and short SVD image-to-video clips for the social composer. One box
 * we own (COMFYUI_HOST), not a per-user integration.
 *
 * The img2vid workflow is best-effort (see docker/comfyui/README.md) —
 * verify it in the ComfyUI web UI on the actual box before relying on it.
 */
class ComfyUiService
{
    private string $host;

    public function __construct()
    {
        $this->host = rtrim((string) config('services.comfyui.host', ''), '/');
    }

    public function isConfigured(): bool
    {
        return $this->host !== '';
    }

    /** Generate an image from a prompt. Returns raw PNG bytes. */
    public function generateImage(string $prompt): string
    {
        $workflow = $this->txt2imgWorkflow($prompt);
        $promptId = $this->submit($workflow);
        $output = $this->waitForOutput($promptId, node: '9', timeoutSeconds: 90);

        return $this->fetchFile($output['filename'], $output['subfolder'] ?? '', $output['type'] ?? 'output');
    }

    /**
     * Generate a short video clip animating a still image. Returns raw MP4
     * bytes (SVD outputs an animated WEBP; we convert it with ffmpeg, which
     * needs to be on this app's own PHP container too — see docker/php/Dockerfile).
     */
    public function generateVideo(string $imageBytes): string
    {
        $uploadedName = $this->uploadImage($imageBytes);
        $workflow = $this->img2vidWorkflow($uploadedName);
        $promptId = $this->submit($workflow);
        $output = $this->waitForOutput($promptId, node: '6', timeoutSeconds: 300);

        $webpBytes = $this->fetchFile($output['filename'], $output['subfolder'] ?? '', $output['type'] ?? 'output');

        return $this->webpToMp4($webpBytes);
    }

    private function submit(array $workflow): string
    {
        $res = Http::timeout(30)->post("{$this->host}/prompt", [
            'prompt' => $workflow,
            'client_id' => (string) Str::uuid(),
        ]);
        if ($res->failed()) {
            throw new \RuntimeException('ComfyUI submit failed: ' . $res->body());
        }
        $promptId = $res->json('prompt_id');
        if (!$promptId) {
            throw new \RuntimeException('ComfyUI did not return a prompt_id: ' . $res->body());
        }
        return $promptId;
    }

    /** Polls /history/{id} until the given node has produced output, or times out. */
    private function waitForOutput(string $promptId, string $node, int $timeoutSeconds): array
    {
        $deadline = time() + $timeoutSeconds;
        while (time() < $deadline) {
            $res = Http::timeout(15)->get("{$this->host}/history/{$promptId}");
            $entry = $res->json($promptId);
            $outputs = $entry['outputs'][$node] ?? null;
            $file = $outputs['images'][0] ?? $outputs['gifs'][0] ?? null;
            if ($file) {
                return $file;
            }
            sleep(2);
        }
        throw new \RuntimeException("ComfyUI generation timed out after {$timeoutSeconds}s (prompt {$promptId}).");
    }

    private function fetchFile(string $filename, string $subfolder, string $type): string
    {
        $res = Http::timeout(30)->get("{$this->host}/view", [
            'filename' => $filename,
            'subfolder' => $subfolder,
            'type' => $type,
        ]);
        if ($res->failed()) {
            throw new \RuntimeException('ComfyUI file fetch failed: ' . $res->body());
        }
        return $res->body();
    }

    private function uploadImage(string $imageBytes): string
    {
        $res = Http::timeout(30)
            ->attach('image', $imageBytes, 'input.png')
            ->post("{$this->host}/upload/image");
        if ($res->failed()) {
            throw new \RuntimeException('ComfyUI image upload failed: ' . $res->body());
        }
        return $res->json('name');
    }

    private function webpToMp4(string $webpBytes): string
    {
        $tmpDir = sys_get_temp_dir();
        $webpPath = $tmpDir . '/' . Str::random(16) . '.webp';
        $mp4Path = $tmpDir . '/' . Str::random(16) . '.mp4';
        file_put_contents($webpPath, $webpBytes);

        $result = Process::timeout(60)->run([
            'ffmpeg', '-y', '-i', $webpPath,
            '-movflags', 'faststart', '-pix_fmt', 'yuv420p',
            '-vf', 'scale=trunc(iw/2)*2:trunc(ih/2)*2',
            $mp4Path,
        ]);

        @unlink($webpPath);
        if (!$result->successful() || !file_exists($mp4Path)) {
            @unlink($mp4Path);
            throw new \RuntimeException('ffmpeg webp->mp4 conversion failed: ' . $result->errorOutput());
        }

        $bytes = file_get_contents($mp4Path);
        @unlink($mp4Path);
        return $bytes;
    }

    private function txt2imgWorkflow(string $prompt): array
    {
        return [
            '3' => [
                'class_type' => 'KSampler',
                'inputs' => [
                    'cfg' => 1.0, 'denoise' => 1.0,
                    'latent_image' => ['5', 0], 'model' => ['4', 0],
                    'negative' => ['7', 0], 'positive' => ['6', 0],
                    'sampler_name' => 'euler_ancestral', 'scheduler' => 'normal',
                    'seed' => random_int(0, PHP_INT_MAX), 'steps' => 4,
                ],
            ],
            '4' => ['class_type' => 'CheckpointLoaderSimple', 'inputs' => ['ckpt_name' => 'sd_xl_turbo_1.0_fp16.safetensors']],
            '5' => ['class_type' => 'EmptyLatentImage', 'inputs' => ['batch_size' => 1, 'height' => 1024, 'width' => 1024]],
            '6' => ['class_type' => 'CLIPTextEncode', 'inputs' => ['clip' => ['4', 1], 'text' => $prompt]],
            '7' => ['class_type' => 'CLIPTextEncode', 'inputs' => ['clip' => ['4', 1], 'text' => '']],
            '8' => ['class_type' => 'VAEDecode', 'inputs' => ['samples' => ['3', 0], 'vae' => ['4', 2]]],
            '9' => ['class_type' => 'SaveImage', 'inputs' => ['filename_prefix' => 'eye_post', 'images' => ['8', 0]]],
        ];
    }

    private function img2vidWorkflow(string $imageName): array
    {
        return [
            '1' => ['class_type' => 'ImageOnlyCheckpointLoader', 'inputs' => ['ckpt_name' => 'svd_xt.safetensors']],
            '2' => ['class_type' => 'LoadImage', 'inputs' => ['image' => $imageName]],
            '3' => [
                'class_type' => 'SVD_img2vid_Conditioning',
                'inputs' => [
                    'clip_vision' => ['1', 1], 'init_image' => ['2', 0], 'vae' => ['1', 2],
                    'width' => 1024, 'height' => 576, 'video_frames' => 25,
                    'motion_bucket_id' => 127, 'fps' => 6, 'augmentation_level' => 0.0,
                ],
            ],
            '4' => [
                'class_type' => 'KSampler',
                'inputs' => [
                    'model' => ['1', 0], 'positive' => ['3', 0], 'negative' => ['3', 1],
                    'latent_image' => ['3', 2], 'seed' => random_int(0, PHP_INT_MAX),
                    'steps' => 20, 'cfg' => 2.5, 'sampler_name' => 'euler', 'scheduler' => 'karras', 'denoise' => 1.0,
                ],
            ],
            '5' => ['class_type' => 'VAEDecode', 'inputs' => ['samples' => ['4', 0], 'vae' => ['1', 2]]],
            '6' => [
                'class_type' => 'SaveAnimatedWEBP',
                'inputs' => [
                    'images' => ['5', 0], 'filename_prefix' => 'eye_post_video',
                    'fps' => 6, 'lossless' => false, 'quality' => 80, 'method' => 'default',
                ],
            ],
        ];
    }
}
