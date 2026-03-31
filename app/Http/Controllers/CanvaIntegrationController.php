<?php

namespace App\Http\Controllers;

use App\Models\CanvaDesign;
use App\Models\CanvaExportJob as CanvaExportJobRecord;
use App\Models\ContentItem;
use App\Services\Canva\CanvaBridgeService;
use App\Services\Canva\CanvaDesignGenerationService;
use App\Services\Canva\CanvaExportService;
use App\Services\Canva\CanvaTemplateCatalogService;
use App\Services\Canva\CanvaTemplateMappingService;
use App\Services\Canva\CanvaTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class CanvaIntegrationController extends Controller
{
    public function redirect(Request $request, CanvaTokenService $tokenService, CanvaBridgeService $bridge): RedirectResponse
    {
        if (!$bridge->isEnabled()) {
            return redirect()->route('settings')->with('status', 'Integrazione Canva non abilitata.');
        }

        try {
            $pkce = $tokenService->generatePkcePayload();
            $request->session()->put('canva_oauth', [
                'state' => $pkce['state'],
                'code_verifier' => $pkce['code_verifier'],
            ]);

            return redirect()->away($tokenService->buildAuthorizationUrl($pkce['state'], $pkce['code_challenge']));
        } catch (Throwable $e) {
            return redirect()->route('settings')->with('status', 'Impossibile avviare Canva OAuth: ' . $e->getMessage());
        }
    }

    public function callback(Request $request, CanvaTokenService $tokenService, CanvaBridgeService $bridge): RedirectResponse
    {
        $expected = (array) $request->session()->pull('canva_oauth', []);
        $incomingState = trim((string) $request->query('state', ''));
        $expectedState = trim((string) ($expected['state'] ?? ''));

        if ($expectedState === '' || !hash_equals($expectedState, $incomingState)) {
            abort(403, 'Canva OAuth state invalid.');
        }

        $code = trim((string) $request->query('code', ''));
        if ($code === '') {
            return redirect()->route('settings')->with('status', 'Canva OAuth non ha restituito un codice valido.');
        }

        try {
            $tokenPayload = $tokenService->exchangeAuthorizationCode($code, (string) ($expected['code_verifier'] ?? ''));
            $bridge->completeOauthConnection((int) $request->user()->tenant_id, (int) $request->user()->id, $tokenPayload);
        } catch (Throwable $e) {
            return redirect()->route('settings')->with('status', 'Connessione Canva fallita: ' . $e->getMessage());
        }

        return redirect()->route('settings')->with('status', 'Connessione Canva completata.');
    }

    public function disconnect(Request $request, CanvaBridgeService $bridge): RedirectResponse
    {
        $bridge->disconnect((int) $request->user()->tenant_id);

        return redirect()->route('settings')->with('status', 'Connessione Canva scollegata.');
    }

    public function refreshTemplates(Request $request, CanvaTemplateCatalogService $catalogService): RedirectResponse
    {
        try {
            $catalogService->refreshCatalogForTenant((int) $request->user()->tenant_id);
        } catch (Throwable $e) {
            return redirect()->route('settings')->with('status', 'Refresh template Canva fallito: ' . $e->getMessage());
        }

        return redirect()->route('settings')->with('status', 'Catalogo template Canva aggiornato.');
    }

    public function updateTemplateMapping(Request $request, CanvaTemplateMappingService $mappingService): RedirectResponse
    {
        $data = $request->validate([
            'channel_format' => 'required|string|max:80',
            'canva_template_id' => 'nullable|string|max:120',
        ]);

        try {
            $mappingService->saveMapping(
                (int) $request->user()->tenant_id,
                (string) $data['channel_format'],
                $data['canva_template_id'] ?? null
            );
        } catch (Throwable $e) {
            return redirect()->route('settings')->with('status', 'Salvataggio mapping Canva fallito: ' . $e->getMessage());
        }

        return redirect()->route('settings')->with('status', 'Mapping Canva salvato.');
    }

    public function sendContentItem(Request $request, ContentItem $contentItem, CanvaDesignGenerationService $designGenerationService): RedirectResponse
    {
        $this->authorizeTenant($request, (int) $contentItem->tenant_id);

        $data = $request->validate([
            'channel_format' => 'nullable|string|max:80',
            'include_generated_visual' => 'nullable|boolean',
            'include_logo' => 'nullable|boolean',
        ]);

        try {
            $design = $designGenerationService->createFromContentItem($contentItem, $data['channel_format'] ?? null, [
                'include_generated_visual' => (bool) ($data['include_generated_visual'] ?? true),
                'include_logo' => (bool) ($data['include_logo'] ?? true),
            ]);
        } catch (Throwable $e) {
            return redirect()->route('content-items.show', $contentItem)->with('status', 'Invio a Canva fallito: ' . $e->getMessage());
        }

        if ($design->status === 'manual_handoff_ready') {
            return redirect()->route('canva.designs.handoff', $design)->with('status', 'Payload Canva pronto per finishing manuale.');
        }

        return redirect()->route('content-items.show', $contentItem)->with('status', 'Design Canva avviato.');
    }

    public function showHandoff(Request $request, CanvaDesign $canvaDesign): View
    {
        $this->authorizeTenant($request, (int) $canvaDesign->tenant_id);
        $canvaDesign->loadMissing('contentItem');

        return view('canva.handoff', [
            'canvaDesign' => $canvaDesign,
        ]);
    }

    public function linkManualDesign(Request $request, CanvaDesign $canvaDesign, CanvaDesignGenerationService $designGenerationService): RedirectResponse
    {
        $this->authorizeTenant($request, (int) $canvaDesign->tenant_id);
        $data = $request->validate([
            'design_url_or_id' => 'required|string|max:255',
        ]);

        try {
            $designGenerationService->linkManualDesign($canvaDesign, (string) $data['design_url_or_id']);
        } catch (Throwable $e) {
            return redirect()->route('canva.designs.handoff', $canvaDesign)->with('status', 'Collegamento design Canva fallito: ' . $e->getMessage());
        }

        return redirect()->route('content-items.show', $canvaDesign->contentItem)->with('status', 'Design Canva collegato.');
    }

    public function export(Request $request, CanvaDesign $canvaDesign, CanvaExportService $exportService): RedirectResponse
    {
        $this->authorizeTenant($request, (int) $canvaDesign->tenant_id);
        $data = $request->validate([
            'export_type' => 'required|string|in:png,pdf,pptx,mp4',
        ]);

        try {
            $exportService->requestExport($canvaDesign, (string) $data['export_type']);
        } catch (Throwable $e) {
            return redirect()->route('content-items.show', $canvaDesign->contentItem)->with('status', 'Export Canva fallito: ' . $e->getMessage());
        }

        return redirect()->route('content-items.show', $canvaDesign->contentItem)->with('status', 'Export Canva richiesto.');
    }

    public function refreshExportStatus(Request $request, CanvaExportJobRecord $canvaExportJob, CanvaExportService $exportService): RedirectResponse
    {
        $this->authorizeTenant($request, (int) $canvaExportJob->tenant_id);
        try {
            $exportService->refreshExportJob($canvaExportJob);
        } catch (Throwable $e) {
            return back()->with('status', 'Refresh export Canva fallito: ' . $e->getMessage());
        }

        return back()->with('status', 'Stato export Canva aggiornato.');
    }

    private function authorizeTenant(Request $request, int $tenantId): void
    {
        if ((int) $request->user()->tenant_id !== $tenantId) {
            abort(403);
        }
    }
}
