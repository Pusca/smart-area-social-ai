<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('platform-admin:ensure {--email=} {--password=} {--name=}', function () {
    $email = strtolower(trim((string) ($this->option('email') ?: config('platform_admin.default_email'))));
    $password = trim((string) ($this->option('password') ?: env('PLATFORM_ADMIN_PASSWORD', '')));
    $name = trim((string) ($this->option('name') ?: config('platform_admin.default_name', 'Social AI Admin')));

    if ($email === '') {
        $this->error('Email admin mancante.');
        return self::FAILURE;
    }

    if ($password === '') {
        $this->error('Password admin mancante. Passa --password oppure imposta PLATFORM_ADMIN_PASSWORD.');
        return self::FAILURE;
    }

    /** @var \App\Models\User $user */
    $user = \App\Models\User::query()->firstOrNew(['email' => $email]);
    $user->name = $name !== '' ? $name : 'Social AI Admin';
    $user->password = $password;
    $user->tenant_id = null;
    $user->role = 'super_admin';
    if ($user->email_verified_at === null) {
        $user->email_verified_at = now();
    }
    $user->save();

    $this->info("Platform admin pronto: {$user->email}");
    $this->line('Ruolo: super_admin');
    $this->line('Tenant: nessuno');

    return self::SUCCESS;
})->purpose('Create or update the single platform admin account');

Artisan::command('media:backfill-video-thumbnails {--tenant=} {--limit=300} {--dry-run}', function () {
    $tenant = (int) ($this->option('tenant') ?: 0);
    $limit = max(1, min(5000, (int) ($this->option('limit') ?: 300)));
    $dryRun = (bool) $this->option('dry-run');

    /** @var \Illuminate\Contracts\Filesystem\Filesystem $disk */
    $disk = Storage::disk('public');
    /** @var \App\Services\OpenAiService $openAi */
    $openAi = app(\App\Services\OpenAiService::class);

    $query = \App\Models\ContentItem::query()->latest('id');
    if ($tenant > 0) {
        $query->where('tenant_id', $tenant);
    }

    $rows = $query->limit($limit)->get();

    $ok = 0;
    $skip = 0;
    $err = 0;

    foreach ($rows as $item) {
        $assetsRaw = $item->assets ?? [];
        $assets = is_string($assetsRaw) ? (json_decode($assetsRaw, true) ?: []) : (is_array($assetsRaw) ? $assetsRaw : []);

        $videoPath = '';
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $path = trim((string) ($asset['path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $type = strtolower(trim((string) ($asset['type'] ?? '')));
            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            if (str_contains($type, 'video') || in_array($ext, ['mp4', 'mov', 'webm', 'm4v', 'avi'], true)) {
                $videoPath = $path;
                break;
            }
        }

        if ($videoPath === '') {
            continue;
        }

        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $thumbPath = trim((string) data_get($meta, 'video_generation.thumbnail_path', ''));
        if ($thumbPath !== '' && $disk->exists($thumbPath)) {
            $skip++;
            continue;
        }

        $videoId = trim((string) data_get($meta, 'video_generation.video_id', ''));
        if ($videoId === '') {
            $skip++;
            continue;
        }

        try {
            $thumbBytes = $openAi->downloadVideoThumbnail($videoId);
            if (!is_string($thumbBytes) || $thumbBytes === '') {
                $skip++;
                continue;
            }

            $ext = 'jpg';
            $sig = substr($thumbBytes, 0, 20);
            if (str_starts_with($sig, "\x89PNG")) {
                $ext = 'png';
            } elseif (str_starts_with($sig, 'RIFF') && str_contains($sig, 'WEBP')) {
                $ext = 'webp';
            }

            $newThumb = 'ai/videos/' . now()->format('Y/m') . '/' . Str::uuid()->toString() . '.' . $ext;

            if (!$dryRun) {
                $disk->put($newThumb, $thumbBytes);

                data_set($meta, 'video_generation.thumbnail_path', $newThumb);
                if (trim((string) data_get($meta, 'video_generation.first_frame_path', '')) === '') {
                    data_set($meta, 'video_generation.first_frame_path', $newThumb);
                }
                $item->ai_meta = $meta;

                $thumbAssetExists = false;
                foreach ($assets as $asset) {
                    if (!is_array($asset)) {
                        continue;
                    }
                    $type = strtolower(trim((string) ($asset['type'] ?? '')));
                    if (str_contains($type, 'thumbnail') || str_contains($type, 'thumb')) {
                        $thumbAssetExists = true;
                        break;
                    }
                }
                if (!$thumbAssetExists) {
                    $assets[] = ['type' => 'ai_generated_thumbnail', 'path' => $newThumb];
                    $item->assets = $assets;
                }

                $aiImagePath = trim((string) ($item->ai_image_path ?? ''));
                $aiExt = strtolower((string) pathinfo($aiImagePath, PATHINFO_EXTENSION));
                if ($aiImagePath === '' || $aiExt === 'svg' || !$disk->exists($aiImagePath)) {
                    $item->ai_image_path = $newThumb;
                }

                $item->save();
            }

            $ok++;
            $this->line("OK item={$item->id} thumb={$newThumb}");
        } catch (\Throwable $e) {
            $err++;
            $this->warn("ERR item={$item->id} {$e->getMessage()}");
        }
    }

    $this->info("Done. ok={$ok} skip={$skip} err={$err} dry_run=" . ($dryRun ? '1' : '0'));
})->purpose('Backfill thumbnails for existing video content items');

Artisan::command('social:publish-due {--tenant=} {--limit=25} {--sync}', function () {
    $tenant = (int) ($this->option('tenant') ?: 0);
    $limit = max(1, min(200, (int) ($this->option('limit') ?: 25)));
    $sync = (bool) $this->option('sync');

    $query = \App\Models\SocialPublication::query()
        ->where('status', 'scheduled')
        ->whereNotNull('scheduled_for')
        ->where('scheduled_for', '<=', now())
        ->with(['contentItem', 'socialAccount'])
        ->orderBy('scheduled_for')
        ->limit($limit);

    if ($tenant > 0) {
        $query->where('tenant_id', $tenant);
    }

    $rows = $query->get();
    $queued = 0;
    $skipped = 0;

    foreach ($rows as $publication) {
        if (!$publication->contentItem || !$publication->socialAccount) {
            $publication->status = 'failed';
            $publication->error_message = 'Pubblicazione orfana: content item o social account mancante.';
            $publication->save();
            $skipped++;
            continue;
        }

        $publication->status = 'processing';
        $publication->last_attempt_at = now();
        $publication->save();

        try {
            if ($sync || app()->environment('local')) {
                \App\Jobs\PublishSocialPublication::dispatchSync((int) $publication->id);
            } else {
                \App\Jobs\PublishSocialPublication::dispatch((int) $publication->id)
                    ->onQueue((string) config('meta.publish_queue', 'social-publish'));
            }
            $queued++;
        } catch (\Throwable $e) {
            $publication->status = 'scheduled';
            $publication->error_message = 'Dispatch publish fallito: ' . $e->getMessage();
            $publication->save();
            $skipped++;
        }
    }

    $this->info("Queued={$queued} skipped={$skipped} sync=" . ($sync ? '1' : '0'));
})->purpose('Dispatch due social publications for Meta-connected accounts');
