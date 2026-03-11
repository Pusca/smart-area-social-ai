<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiForContentItem;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Support\ImageProviderResolver;
use App\Support\VideoProviderResolver;
use Illuminate\Http\Request;

class AiGenerateController extends Controller
{
    public function generateOne(Request $request, ContentItem $contentItem)
    {
        $this->authorizeItemTenant($request, $contentItem);

        $this->applyVideoProviderPreference($contentItem, (string) $request->input('video_provider', ''));
        $this->applyImageProviderPreference($contentItem, (string) $request->input('image_provider', ''));

        $contentItem->ai_status = 'queued';
        $contentItem->ai_error = null;
        $contentItem->save();

        if (app()->environment('local')) {
            GenerateAiForContentItem::dispatchSync($contentItem->id);
        } else {
            GenerateAiForContentItem::dispatch($contentItem->id);
        }

        return back()->with('status', app()->environment('local')
            ? 'Rigenerazione AI completata (sync locale).'
            : 'Rigenerazione AI messa in coda (JOBv4).'
        );
    }

    public function generatePlan(Request $request, ContentPlan $contentPlan)
    {
        $this->authorizePlanTenant($request, $contentPlan);

        $videoProviderInput = (string) $request->input('video_provider', '');
        $items = ContentItem::where('content_plan_id', $contentPlan->id)->get();

        foreach ($items as $item) {
            $this->applyVideoProviderPreference($item, $videoProviderInput);
            $this->applyImageProviderPreference($item, '');
            $item->ai_status = 'queued';
            $item->ai_error = null;
            $item->save();

            GenerateAiForContentItem::dispatch($item->id);
        }

        return back()->with('status', 'Rigenerazione AI del piano messa in coda (background).');
    }

    public function generateImage(Request $request, ContentItem $contentItem)
    {
        $this->authorizeItemTenant($request, $contentItem);

        $this->applyVideoProviderPreference($contentItem, (string) $request->input('video_provider', ''));
        $this->applyImageProviderPreference($contentItem, (string) $request->input('image_provider', ''));

        $contentItem->ai_status = 'queued';
        $contentItem->ai_error = null;
        $contentItem->save();

        if (app()->environment('local')) {
            GenerateAiForContentItem::dispatchSync($contentItem->id);
        } else {
            GenerateAiForContentItem::dispatch($contentItem->id);
        }

        return back()->with('status', app()->environment('local')
            ? 'Rigenerazione visual completata (sync locale).'
            : 'Rigenerazione visual messa in coda.');
    }

    private function applyVideoProviderPreference(ContentItem $item, string $requestedProvider): string
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $existingProvider = (string) data_get($meta, 'video_provider', '');
        $provider = VideoProviderResolver::resolve($requestedProvider, $existingProvider);
        $meta['video_provider'] = $provider;
        $item->ai_meta = $meta;

        return $provider;
    }

    private function applyImageProviderPreference(ContentItem $item, string $requestedProvider): string
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $existingProvider = (string) data_get($meta, 'image_provider', '');
        $provider = $this->allowsCustomImageProvider($item)
            ? ImageProviderResolver::resolve($requestedProvider, $existingProvider)
            : ImageProviderResolver::default();
        $meta['image_provider'] = $provider;
        $item->ai_meta = $meta;

        return $provider;
    }

    private function allowsCustomImageProvider(ContentItem $item): bool
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $source = trim((string) data_get($meta, 'source', ''));
        if ($source === 'manual_single_content') {
            return true;
        }

        $mode = trim((string) data_get($meta, 'plan.mode', ''));
        if ($mode === 'single_manual') {
            return true;
        }

        return trim((string) data_get($item->plan?->settings, 'mode', '')) === 'single_manual';
    }

    private function authorizeItemTenant(Request $request, ContentItem $item): void
    {
        if ((int) $item->tenant_id !== (int) $request->user()->tenant_id) {
            abort(403);
        }
    }

    private function authorizePlanTenant(Request $request, ContentPlan $plan): void
    {
        if ((int) $plan->tenant_id !== (int) $request->user()->tenant_id) {
            abort(403);
        }
    }
}
