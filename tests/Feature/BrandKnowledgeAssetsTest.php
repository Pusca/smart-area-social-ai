<?php

namespace Tests\Feature;

use App\Models\BrandAsset;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\User;
use App\Services\AI\TenantContentIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandKnowledgeAssetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_center_store_accepts_documents_notes_and_links(): void
    {
        Storage::fake('public');

        $tenant = Tenant::create([
            'name' => 'Demo Tenant',
            'slug' => 'demo-tenant',
            'plan' => 'trial',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user)->post(route('profile.brand.store'), [
            'business_name' => 'Studio Alfa',
            'industry' => 'Consulenza',
            'asset_upload_notes' => 'Usa questi materiali solo per informazioni reali e verificate.',
            'documents' => [
                UploadedFile::fake()->createWithContent('faq.txt', "Consegna in 48 ore.\nSupporto via WhatsApp.\n"),
            ],
            'knowledge_text_title' => 'FAQ operative',
            'knowledge_text_body' => "Consegna in 48 ore.\nSopralluogo gratuito su Milano.",
            'knowledge_text_notes' => 'Usala quando il contenuto richiede dettagli di servizio.',
            'reference_link_label' => 'Catalogo ufficiale',
            'reference_link_url' => 'https://example.com/catalogo',
            'reference_link_notes' => 'Pagina con servizi e prezzi aggiornati.',
        ]);

        $response->assertRedirect(route('profile.brand'));

        $assets = BrandAsset::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $assets);

        $document = $assets->firstWhere('kind', 'document');
        $this->assertNotNull($document);
        $this->assertNotSame('', (string) $document->path);
        $this->assertSame('uploaded_document', data_get($document->meta, 'content_origin'));
        $this->assertSame('available', data_get($document->meta, 'text_extract_status'));
        $this->assertSame('Usa questi materiali solo per informazioni reali e verificate.', data_get($document->meta, 'grounding_notes'));
        $this->assertStringContainsString('Consegna in 48 ore.', (string) data_get($document->meta, 'text_excerpt'));
        Storage::disk('public')->assertExists((string) $document->path);

        $text = $assets->firstWhere('kind', 'text');
        $this->assertNotNull($text);
        $this->assertSame('', (string) $text->path);
        $this->assertSame('FAQ operative', (string) $text->original_name);
        $this->assertSame('manual_text_entry', data_get($text->meta, 'content_origin'));
        $this->assertStringContainsString('Sopralluogo gratuito su Milano.', (string) data_get($text->meta, 'knowledge_text'));
        $this->assertSame('Usala quando il contenuto richiede dettagli di servizio.', data_get($text->meta, 'grounding_notes'));

        $link = $assets->firstWhere('kind', 'link');
        $this->assertNotNull($link);
        $this->assertSame('', (string) $link->path);
        $this->assertSame('Catalogo ufficiale', (string) $link->original_name);
        $this->assertSame('reference_link', data_get($link->meta, 'content_origin'));
        $this->assertSame('https://example.com/catalogo', data_get($link->meta, 'source_url'));
        $this->assertSame('Pagina con servizi e prezzi aggiornati.', data_get($link->meta, 'grounding_notes'));
    }

    public function test_intelligence_service_exposes_document_text_and_link_assets_in_knowledge_pack(): void
    {
        $tenant = Tenant::create([
            'name' => 'Demo Tenant',
            'slug' => 'demo-tenant',
            'plan' => 'trial',
            'is_active' => true,
        ]);

        TenantProfile::create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Studio Alfa',
            'industry' => 'Consulenza',
            'default_tone' => 'professionale',
            'cta' => 'Scrivici per una consulenza',
        ]);

        BrandAsset::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => null,
            'kind' => 'document',
            'path' => 'brand-assets/' . $tenant->id . '/faq.pdf',
            'original_name' => 'faq.pdf',
            'mime' => 'application/pdf',
            'meta' => [
                'source' => 'brand_center',
                'content_origin' => 'uploaded_document',
                'grounding_notes' => 'Listino ufficiale',
                'text_excerpt' => 'Consegna in 48 ore e assistenza post vendita.',
            ],
        ]);

        BrandAsset::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => null,
            'kind' => 'text',
            'path' => '',
            'original_name' => 'FAQ showroom',
            'mime' => 'text/plain',
            'meta' => [
                'source' => 'brand_center',
                'content_origin' => 'manual_text_entry',
                'text_title' => 'FAQ showroom',
                'knowledge_text' => 'Aperto il sabato e riceve su appuntamento.',
                'text_excerpt' => 'Aperto il sabato e riceve su appuntamento.',
            ],
        ]);

        BrandAsset::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => null,
            'kind' => 'link',
            'path' => '',
            'original_name' => 'Menu online',
            'mime' => 'text/uri-list',
            'meta' => [
                'source' => 'brand_center',
                'content_origin' => 'reference_link',
                'source_label' => 'Menu online',
                'source_url' => 'https://example.com/menu',
                'text_excerpt' => 'Pagina ufficiale con piatti e prezzi.',
            ],
        ]);

        /** @var TenantContentIntelligenceService $service */
        $service = app(TenantContentIntelligenceService::class);
        $result = $service->buildForGeneration($tenant->id, 'Promuovi il menu', 'post', ['instagram']);

        $this->assertSame(1, data_get($result, 'knowledge_pack.asset_counts.documents'));
        $this->assertSame(1, data_get($result, 'knowledge_pack.asset_counts.texts'));
        $this->assertSame(1, data_get($result, 'knowledge_pack.asset_counts.links'));
        $this->assertStringContainsString(
            'Consegna in 48 ore',
            (string) data_get($result, 'knowledge_pack.asset_library.documents.0.text_excerpt')
        );
        $this->assertSame(
            'FAQ showroom',
            data_get($result, 'knowledge_pack.asset_library.texts.0.source_label')
        );
        $this->assertSame(
            'https://example.com/menu',
            data_get($result, 'knowledge_pack.asset_library.links.0.source_url')
        );
        $this->assertNotEmpty(data_get($result, 'knowledge_pack.asset_library.knowledge_sources'));
    }

    public function test_brand_center_page_renders_with_knowledge_assets(): void
    {
        $tenant = Tenant::create([
            'name' => 'Demo Tenant',
            'slug' => 'demo-tenant',
            'plan' => 'trial',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ]);

        TenantProfile::create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Studio Alfa',
            'industry' => 'Consulenza',
        ]);

        BrandAsset::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => null,
            'kind' => 'document',
            'path' => 'brand-assets/' . $tenant->id . '/faq.pdf',
            'original_name' => 'faq.pdf',
            'mime' => 'application/pdf',
            'meta' => [
                'source' => 'brand_center',
                'content_origin' => 'uploaded_document',
                'text_excerpt' => 'Consegna in 48 ore e supporto via WhatsApp.',
            ],
        ]);

        BrandAsset::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => null,
            'kind' => 'text',
            'path' => '',
            'original_name' => 'FAQ showroom',
            'mime' => 'text/plain',
            'meta' => [
                'source' => 'brand_center',
                'content_origin' => 'manual_text_entry',
                'text_excerpt' => 'Aperto il sabato su appuntamento.',
            ],
        ]);

        BrandAsset::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => null,
            'kind' => 'link',
            'path' => '',
            'original_name' => 'Catalogo ufficiale',
            'mime' => 'text/uri-list',
            'meta' => [
                'source' => 'brand_center',
                'content_origin' => 'reference_link',
                'source_url' => 'https://example.com/catalogo',
                'text_excerpt' => 'Pagina ufficiale con servizi e prezzi.',
            ],
        ]);

        $this->actingAs($user)
            ->get(route('profile.brand'))
            ->assertOk()
            ->assertSee('Documenti')
            ->assertSee('Note di conoscenza')
            ->assertSee('Link di riferimento');
    }

    public function test_setup_page_renders_knowledge_asset_summary(): void
    {
        $tenant = Tenant::create([
            'name' => 'Demo Tenant',
            'slug' => 'demo-tenant',
            'plan' => 'trial',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ]);

        TenantProfile::create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Studio Alfa',
            'industry' => 'Consulenza',
        ]);

        BrandAsset::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => null,
            'kind' => 'text',
            'path' => '',
            'original_name' => 'FAQ showroom',
            'mime' => 'text/plain',
            'meta' => [
                'source' => 'brand_center',
                'content_origin' => 'manual_text_entry',
                'text_excerpt' => 'Aperto il sabato su appuntamento.',
            ],
        ]);

        $this->actingAs($user)
            ->get(route('settings'))
            ->assertOk()
            ->assertSee('asset di conoscenza');
    }

    public function test_intelligence_service_tolerates_non_utf8_brief_text(): void
    {
        $tenant = Tenant::create([
            'name' => 'Demo Tenant',
            'slug' => 'demo-tenant',
            'plan' => 'trial',
            'is_active' => true,
        ]);

        TenantProfile::create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Studio Alfa',
            'industry' => 'Consulenza',
        ]);

        $brief = mb_convert_encoding('Fai un reel più diretto sul listino', 'Windows-1252', 'UTF-8');

        /** @var TenantContentIntelligenceService $service */
        $service = app(TenantContentIntelligenceService::class);
        $result = $service->buildForGeneration($tenant->id, $brief, 'reel', ['instagram']);

        $this->assertIsArray($result);
        $this->assertSame('reel', data_get($result, 'knowledge_pack.brief_focus.requested_format'));
    }
}
