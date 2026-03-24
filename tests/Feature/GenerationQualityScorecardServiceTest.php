<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\GenerationRun;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\User;
use App\Services\AI\GenerationQualityScorecardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerationQualityScorecardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_quality_scorecard_from_real_alignment_signals(): void
    {
        [$tenant, $user] = $this->bootstrapTenant('tenant-quality-pass');
        $plan = $this->createPlan($tenant, $user);
        $item = $this->createContentItem($tenant, $user, $plan, [
            'ai_status' => 'done',
            'ai_caption' => 'Una caption forte e coerente con il brand, pensata per far capire subito il valore del locale.',
            'ai_cta' => 'Prenota ora.',
            'ai_image_prompt' => 'Fotografia editoriale photorealistic del locale con luce naturale e atmosfera vera.',
            'ai_image_path' => 'ai/2026/03/quality-pass.png',
            'ai_meta' => [
                'tenant_profile' => [
                    'cta' => 'Prenota ora.',
                ],
                'manual_brief' => 'Mostra il ristorante in modo realistico e premium.',
                'text_alignment_review' => [
                    'overall_score' => 0.84,
                    'heuristic' => [
                        'cta_score' => 0.93,
                        'hard_rule_violations' => [],
                    ],
                    'llm' => [
                        'brand_alignment_score' => 0.88,
                        'brief_alignment_score' => 0.81,
                        'issues' => [],
                    ],
                ],
                'strategy' => [
                    'creative_direction' => [
                        'typography_system' => ['overlay_mode' => 'layout_safe_area_only'],
                        'trend_policy' => ['usage_mode' => 'adapt_selectively'],
                        'continuity_rules' => ['reference_policy' => 'Preserva ancore reali'],
                    ],
                ],
                'item_brain' => [
                    'overlay_brief' => 'safe area upper third, max 5 parole',
                    'trend_bridge' => 'Adatta il trend al brand',
                    'continuity_brief' => 'Mantieni il locale reale',
                ],
                'content_strategy' => [
                    'strategy_type' => 'authoritative',
                ],
                'hook_meta' => [
                    'main_hook' => 'Prima di raccontare il locale, fai capire il criterio che lo rende memorabile.',
                    'alternative_hook' => 'Il punto non e solo l atmosfera: e cosa resta davvero in testa dopo la visita.',
                    'platform_specific_opening_structure' => 'Prima riga fermascroll, seconda dettaglio reale, terza payoff.',
                    'cta_mode' => 'consultative_soft',
                ],
                'authority_signals' => [
                    ['type' => 'operational_detail', 'cue' => 'Dettaglio operativo reale del locale.'],
                    ['type' => 'decision_criterion', 'cue' => 'Criterio concreto per leggere il posizionamento.'],
                ],
                'trust_signals' => [
                    ['type' => 'observable_proof', 'cue' => 'Segnale osservabile nel locale reale.'],
                    ['type' => 'specific_constraint', 'cue' => 'Specificita reale e non generica.'],
                ],
                'viral_angle' => [
                    'primary' => 'Insight che ribalta la lettura superficiale',
                    'mechanic' => 'authority_problem_reframe',
                ],
                'content_structure_meta' => [
                    'opening_structure' => 'Prima riga fermascroll, seconda dettaglio reale, terza payoff.',
                    'body_flow' => 'Hook -> reframe -> criterio -> CTA.',
                    'closing_structure' => 'Invito naturale ad approfondire.',
                ],
                'image_generation' => [
                    'provider' => 'openai',
                    'source' => 'brand_image_edit',
                    'brand_source_paths' => ['brand-assets/1/hero.jpg'],
                    'alignment_review' => [
                        'all_present' => true,
                        'confidence' => 0.86,
                        'missing_indexes' => [],
                        'summary' => 'Il riferimento principale e presente.',
                    ],
                ],
            ],
        ]);

        $run = GenerationRun::create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $item->id,
            'content_plan_id' => $plan->id,
            'run_key' => 'quality-pass-run',
            'scope' => 'content_item',
            'trigger_source' => 'job',
            'status' => 'succeeded',
            'format' => 'post',
            'platform' => 'instagram',
            'fallback_used' => false,
            'downgrade_used' => false,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'completed_at' => now(),
        ]);

        $scorecard = app(GenerationQualityScorecardService::class)->buildForContentItem($item, $run);

        $this->assertSame('pass_with_warnings', $scorecard['publish_readiness_status']);
        $this->assertEquals(0.88, $scorecard['brand_voice_score']);
        $this->assertEquals(0.93, $scorecard['cta_compliance_score']);
        $this->assertEquals(0.86, $scorecard['reference_match_score']);
        $this->assertEquals(0.86, $scorecard['visual_identity_score']);
        $this->assertGreaterThan(0.70, (float) $scorecard['realism_score']);
        $this->assertEmpty($scorecard['blocking_reasons']);
        $this->assertNotEmpty($scorecard['warnings']);
        $this->assertSame('validated', data_get($scorecard, 'score_sources.creative_direction_review.mode'));
        $this->assertSame('validated', data_get($scorecard, 'score_sources.content_strategy_review.mode'));
        $this->assertTrue((bool) data_get($scorecard, 'creative_direction_review.strategy_present'));
        $this->assertTrue((bool) data_get($scorecard, 'content_strategy_review.root_persisted'));
        $this->assertSame('authoritative', data_get($scorecard, 'content_strategy_review.strategy_type'));
        $this->assertGreaterThan(0.75, (float) data_get($scorecard, 'professionalism_score'));
        $this->assertGreaterThan(0.65, (float) data_get($scorecard, 'hook_strength_score'));
        $this->assertSame('validated', data_get($scorecard, 'score_sources.professionalism_score.mode'));
        $this->assertSame('heuristic', data_get($scorecard, 'score_sources.hook_strength_score.mode'));
        $this->assertSame('missing', data_get($scorecard, 'score_sources.trend_relevance_score.mode'));
        $this->assertStringContainsString('Realism score basato', implode(' ', (array) $scorecard['warnings']));

        app(GenerationQualityScorecardService::class)->storeOnContentItem($item, $scorecard, $run);
        $item->save();
        $item->refresh();

        $this->assertSame('pass_with_warnings', data_get($item->ai_meta, 'quality_scorecard.publish_readiness_status'));
        $this->assertSame($run->id, data_get($item->ai_meta, 'generation_audit.quality_scorecard_run_id'));
    }

    public function test_it_blocks_publish_readiness_when_generation_failed_or_visual_output_is_missing(): void
    {
        [$tenant, $user] = $this->bootstrapTenant('tenant-quality-blocked');
        $plan = $this->createPlan($tenant, $user);
        $item = $this->createContentItem($tenant, $user, $plan, [
            'ai_status' => 'error',
            'ai_error' => 'STRICT_MODE_NO_VISUAL_OUTPUT',
            'ai_meta' => [
                'manual_brief' => 'Mostra il locale con la titolare.',
                'text_alignment_review' => [
                    'overall_score' => 0.52,
                    'heuristic' => [
                        'cta_score' => 0.35,
                        'hard_rule_violations' => [],
                    ],
                ],
            ],
        ]);

        $run = GenerationRun::create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $item->id,
            'content_plan_id' => $plan->id,
            'run_key' => 'quality-blocked-run',
            'scope' => 'content_item',
            'trigger_source' => 'job',
            'status' => 'failed',
            'format' => 'reel',
            'platform' => 'instagram',
            'fallback_used' => false,
            'downgrade_used' => false,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'failed_at' => now(),
        ]);

        $scorecard = app(GenerationQualityScorecardService::class)->buildForContentItem($item, $run);

        $this->assertSame('blocked', $scorecard['publish_readiness_status']);
        $this->assertContains('Manca un asset visuale finale utilizzabile.', $scorecard['blocking_reasons']);
        $this->assertContains('La generazione non e completata correttamente.', $scorecard['blocking_reasons']);
    }

    public function test_it_blocks_publish_readiness_for_blocked_trend_risk_flags(): void
    {
        [$tenant, $user] = $this->bootstrapTenant('tenant-quality-trend-blocked');
        $plan = $this->createPlan($tenant, $user);
        $item = $this->createContentItem($tenant, $user, $plan, [
            'ai_status' => 'done',
            'ai_caption' => 'Caption coerente ma appoggiata a un trend non adatto.',
            'ai_cta' => 'Prenota ora.',
            'ai_image_path' => 'ai/2026/03/trend-blocked.png',
            'ai_meta' => [
                'strategy' => [
                    'trend_intelligence' => [
                        'summary' => ['opportunities_count' => 1],
                    ],
                ],
                'item_brain' => [
                    'trend_bridge' => 'Usa il trend audio meme',
                    'trend_opportunity' => [
                        'brand_fit_score' => 0.41,
                        'execution_feasibility_score' => 0.73,
                        'risk_flags' => ['brand_conflict'],
                    ],
                ],
                'text_alignment_review' => [
                    'overall_score' => 0.84,
                    'heuristic' => [
                        'cta_score' => 0.88,
                        'hard_rule_violations' => [],
                    ],
                ],
            ],
        ]);

        $run = GenerationRun::create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $item->id,
            'content_plan_id' => $plan->id,
            'run_key' => 'quality-trend-blocked-run',
            'scope' => 'content_item',
            'trigger_source' => 'job',
            'status' => 'succeeded',
            'format' => 'post',
            'platform' => 'instagram',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'completed_at' => now(),
        ]);

        $scorecard = app(GenerationQualityScorecardService::class)->buildForContentItem($item, $run);

        $this->assertSame('blocked', $scorecard['publish_readiness_status']);
        $this->assertContains(
            'La trend opportunity contiene un rischio incompatibile con il brand o con la publish readiness.',
            $scorecard['blocking_reasons']
        );
    }
    public function test_it_warns_when_trend_metadata_lacks_basis_or_guardrails(): void
    {
        [$tenant, $user] = $this->bootstrapTenant('tenant-quality-trend-metadata');
        $plan = $this->createPlan($tenant, $user);
        $item = $this->createContentItem($tenant, $user, $plan, [
            'ai_status' => 'done',
            'ai_caption' => 'Caption coerente ma con metadata trend incompleti.',
            'ai_cta' => 'Prenota ora.',
            'ai_image_path' => 'ai/2026/03/trend-warning.png',
            'ai_meta' => [
                'strategy' => [
                    'trend_intelligence' => [
                        'summary' => ['opportunities_count' => 1],
                    ],
                ],
                'item_brain' => [
                    'trend_bridge' => 'Usa il trend come struttura.',
                    'trend_usage_mode' => 'trend_safe_adaptation',
                    'trend_confidence' => 0.71,
                    'trend_opportunity' => [
                        'brand_fit_score' => 0.73,
                        'execution_feasibility_score' => 0.77,
                        'risk_flags' => [],
                    ],
                ],
                'text_alignment_review' => [
                    'overall_score' => 0.84,
                    'heuristic' => [
                        'cta_score' => 0.88,
                        'hard_rule_violations' => [],
                    ],
                ],
            ],
        ]);

        $run = GenerationRun::create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $item->id,
            'content_plan_id' => $plan->id,
            'run_key' => 'quality-trend-warning-run',
            'scope' => 'content_item',
            'trigger_source' => 'job',
            'status' => 'succeeded',
            'format' => 'post',
            'platform' => 'instagram',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'completed_at' => now(),
        ]);

        $scorecard = app(GenerationQualityScorecardService::class)->buildForContentItem($item, $run);

        $warnings = implode(' ', (array) $scorecard['warnings']);
        $this->assertStringContainsString('trend_basis strutturato', $warnings);
        $this->assertStringContainsString('professionality guardrails', $warnings);
        $this->assertStringContainsString('expected_engagement_goal', $warnings);
    }

    public function test_it_warns_when_reel_content_strategy_metadata_is_missing(): void
    {
        [$tenant, $user] = $this->bootstrapTenant('tenant-quality-strategy-missing');
        $plan = $this->createPlan($tenant, $user);
        $item = $this->createContentItem($tenant, $user, $plan, [
            'format' => 'reel',
            'ai_status' => 'done',
            'ai_caption' => 'Caption reel coerente ma senza metadata hook strutturati.',
            'ai_cta' => 'Prenota ora.',
            'ai_image_path' => 'ai/2026/03/strategy-missing.png',
            'ai_meta' => [
                'text_alignment_review' => [
                    'overall_score' => 0.81,
                    'heuristic' => [
                        'cta_score' => 0.86,
                        'hard_rule_violations' => [],
                    ],
                ],
            ],
        ]);

        $run = GenerationRun::create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $item->id,
            'content_plan_id' => $plan->id,
            'run_key' => 'quality-strategy-missing-run',
            'scope' => 'content_item',
            'trigger_source' => 'job',
            'status' => 'succeeded',
            'format' => 'reel',
            'platform' => 'instagram',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'completed_at' => now(),
        ]);

        $scorecard = app(GenerationQualityScorecardService::class)->buildForContentItem($item, $run);

        $warnings = implode(' ', (array) $scorecard['warnings']);
        $this->assertStringContainsString('hook_meta strutturato', $warnings);
        $this->assertStringContainsString('content_structure_meta.video_segments', $warnings);
    }

    public function test_it_blocks_trend_aware_video_with_weak_hook_low_professionalism_and_unreadable_overlay(): void
    {
        [$tenant, $user] = $this->bootstrapTenant('tenant-quality-aggressive-trend');
        $plan = $this->createPlan($tenant, $user);
        $item = $this->createContentItem($tenant, $user, $plan, [
            'format' => 'reel',
            'ai_status' => 'done',
            'ai_caption' => 'Compra subito, ultima occasione per non perdere il trend.',
            'ai_cta' => 'Compra subito!!!',
            'ai_image_path' => 'ai/2026/03/aggressive-trend.png',
            'ai_meta' => [
                'text_alignment_review' => [
                    'overall_score' => 0.41,
                    'heuristic' => [
                        'cta_score' => 0.34,
                        'hard_rule_violations' => [],
                    ],
                    'llm' => [
                        'brand_alignment_score' => 0.32,
                        'brief_alignment_score' => 0.46,
                        'issues' => ['Aggressivo', 'Off-brand'],
                    ],
                ],
                'content_strategy' => [
                    'strategy_type' => 'trend-aware',
                    'selection_context' => [
                        'trend_relevance' => 'high',
                    ],
                ],
                'hook_meta' => [
                    'main_hook' => 'DIVENTA VIRALE SUBITO!!!',
                    'alternative_hook' => 'Il trucco definitivo.',
                    'platform_specific_opening_structure' => 'Apri urlando il trend e spingi la CTA.',
                ],
                'item_brain' => [
                    'content_strategy_type' => 'trend-aware',
                    'trend_bridge' => 'Usa il trend del momento',
                    'trend_usage_mode' => 'reactive_commentary',
                    'trend_confidence' => 0.33,
                    'trend_opportunity' => [
                        'brand_fit_score' => 0.29,
                        'execution_feasibility_score' => 0.41,
                        'viral_potential_score' => 0.71,
                        'risk_flags' => [],
                    ],
                ],
                'overlay_meta' => [
                    'mode' => 'auto',
                    'templates' => [
                        [
                            'role' => 'hook',
                            'text' => 'DIVENTA VIRALE SUBITO!!!',
                            'safe_area' => 'center_safe',
                            'position' => 'center',
                            'max_lines' => 3,
                        ],
                    ],
                    'readability' => [
                        'contrast_score' => 0.31,
                        'safe_area_score' => 0.49,
                        'overlap_risk' => 0.71,
                        'mobile_readability' => 0.34,
                        'overall_score' => 0.33,
                        'warnings' => [],
                    ],
                ],
                'storyboard_meta' => [
                    'scene_count' => 3,
                    'hook_scene_present' => true,
                    'cta_scene_present' => true,
                    'scene_list' => [
                        [
                            'scene_index' => 1,
                            'scene_type' => 'hook',
                            'timing_window' => ['start_ms' => 0, 'end_ms' => 4200],
                            'voiceover_segment' => '',
                            'text_overlay' => [
                                'text' => 'DIVENTA VIRALE SUBITO!!!',
                                'safe_area' => 'center_safe',
                                'position' => 'center',
                            ],
                        ],
                    ],
                ],
                'asset_scoring' => [
                    'identity_confidence' => 0.38,
                ],
            ],
        ]);

        $run = GenerationRun::create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $item->id,
            'content_plan_id' => $plan->id,
            'run_key' => 'quality-aggressive-trend-run',
            'scope' => 'content_item',
            'trigger_source' => 'job',
            'status' => 'succeeded',
            'format' => 'reel',
            'platform' => 'instagram',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'completed_at' => now(),
        ]);

        $scorecard = app(GenerationQualityScorecardService::class)->buildForContentItem($item, $run);

        $this->assertSame('blocked', $scorecard['publish_readiness_status']);
        $this->assertLessThan(0.36, (float) data_get($scorecard, 'professionalism_score'));
        $this->assertLessThan(0.34, (float) data_get($scorecard, 'overlay_readability_score'));
        $this->assertLessThan(0.42, (float) data_get($scorecard, 'trend_relevance_score'));
        $this->assertLessThan(0.46, (float) data_get($scorecard, 'trend_brand_fit_score'));
        $this->assertLessThan(0.34, (float) data_get($scorecard, 'hook_strength_score'));
        $this->assertSame('validated', data_get($scorecard, 'score_sources.professionalism_score.mode'));
        $this->assertSame('validated', data_get($scorecard, 'score_sources.trend_relevance_score.mode'));
        $this->assertSame('validated', data_get($scorecard, 'score_sources.trend_brand_fit_score.mode'));
        $this->assertSame('validated', data_get($scorecard, 'score_sources.overlay_readability_score.mode'));
        $blocking = implode(' ', (array) $scorecard['blocking_reasons']);
        $this->assertStringContainsString('Professionalism score troppo basso', $blocking);
        $this->assertStringContainsString('Hook troppo debole', $blocking);
        $this->assertStringContainsString('Contenuto fuori trend', $blocking);
        $this->assertStringContainsString('Overlay troppo poco leggibile', $blocking);
    }

    public function test_it_marks_new_scores_as_heuristic_or_missing_when_structured_signals_are_not_available(): void
    {
        [$tenant, $user] = $this->bootstrapTenant('tenant-quality-heuristic');
        $plan = $this->createPlan($tenant, $user);
        $item = $this->createContentItem($tenant, $user, $plan, [
            'ai_status' => 'done',
            'ai_caption' => 'Metodo chiaro, non rumore.',
            'ai_cta' => 'Scrivici',
            'ai_image_path' => 'ai/2026/03/heuristic.png',
            'ai_meta' => [
                'hook_meta' => [
                    'main_hook' => 'Metodo chiaro, non rumore.',
                ],
            ],
        ]);

        $run = GenerationRun::create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $item->id,
            'content_plan_id' => $plan->id,
            'run_key' => 'quality-heuristic-run',
            'scope' => 'content_item',
            'trigger_source' => 'job',
            'status' => 'succeeded',
            'format' => 'post',
            'platform' => 'instagram',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'completed_at' => now(),
        ]);

        $scorecard = app(GenerationQualityScorecardService::class)->buildForContentItem($item, $run);

        $this->assertSame('heuristic', data_get($scorecard, 'score_sources.professionalism_score.mode'));
        $this->assertSame('heuristic', data_get($scorecard, 'score_sources.hook_strength_score.mode'));
        $this->assertSame('missing', data_get($scorecard, 'score_sources.trend_relevance_score.mode'));
        $this->assertSame('missing', data_get($scorecard, 'score_sources.overlay_readability_score.mode'));
        $this->assertSame('missing', data_get($scorecard, 'score_sources.mobile_legibility_score.mode'));
    }

    public function test_it_marks_trend_relevance_as_heuristic_when_only_strategy_label_exists_without_trend_intelligence(): void
    {
        [$tenant, $user] = $this->bootstrapTenant('tenant-quality-trend-label-only');
        $plan = $this->createPlan($tenant, $user);
        $item = $this->createContentItem($tenant, $user, $plan, [
            'ai_status' => 'done',
            'ai_caption' => 'Copy pulito con un angolo social-native.',
            'ai_cta' => 'Scrivici',
            'ai_image_path' => 'ai/2026/03/trend-label-only.png',
            'ai_meta' => [
                'content_strategy' => [
                    'strategy_type' => 'trend-aware',
                    'selection_context' => [
                        'trend_relevance' => 'high',
                    ],
                ],
                'hook_meta' => [
                    'main_hook' => 'Formato del momento, ma tradotto bene.',
                ],
            ],
        ]);

        $run = GenerationRun::create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $item->id,
            'content_plan_id' => $plan->id,
            'run_key' => 'quality-trend-label-only-run',
            'scope' => 'content_item',
            'trigger_source' => 'job',
            'status' => 'succeeded',
            'format' => 'post',
            'platform' => 'instagram',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'completed_at' => now(),
        ]);

        $scorecard = app(GenerationQualityScorecardService::class)->buildForContentItem($item, $run);

        $this->assertSame('heuristic', data_get($scorecard, 'score_sources.trend_relevance_score.mode'));
        $this->assertSame(
            'content_strategy.selection_context_without_structured_trend_signal',
            data_get($scorecard, 'score_sources.trend_relevance_score.source')
        );
    }

    public function test_it_reviews_storyboard_presence_hook_cta_and_safe_areas_for_reels(): void
    {
        [$tenant, $user] = $this->bootstrapTenant('tenant-quality-storyboard');
        $plan = $this->createPlan($tenant, $user);
        $item = $this->createContentItem($tenant, $user, $plan, [
            'format' => 'reel',
            'ai_status' => 'done',
            'ai_caption' => 'Caption reel con storyboard completo.',
            'ai_cta' => 'Scrivici ora.',
            'ai_image_path' => 'ai/2026/03/storyboard-quality.png',
            'ai_meta' => [
                'text_alignment_review' => [
                    'overall_score' => 0.81,
                    'heuristic' => [
                        'cta_score' => 0.86,
                        'hard_rule_violations' => [],
                    ],
                ],
                'storyboard_meta' => [
                    'scene_count' => 4,
                    'hook_scene_present' => true,
                    'cta_scene_present' => true,
                    'identity_first' => true,
                    'scene_list' => [
                        [
                            'scene_index' => 1,
                            'scene_type' => 'hook',
                            'voiceover_segment' => 'Apri sul dettaglio forte.',
                            'CTA_role' => 'none',
                            'text_overlay' => [
                                'text' => 'Dettaglio forte',
                                'position' => 'upper_left',
                                'safe_area' => 'upper_third',
                                'avoid_regions' => ['center_face_zone'],
                            ],
                        ],
                        [
                            'scene_index' => 2,
                            'scene_type' => 'development',
                            'voiceover_segment' => 'Sviluppa la scena.',
                            'CTA_role' => 'none',
                            'text_overlay' => [
                                'text' => 'Passaggio chiave',
                                'position' => 'upper_left',
                                'safe_area' => 'upper_third',
                                'avoid_regions' => ['center_face_zone'],
                            ],
                        ],
                        [
                            'scene_index' => 3,
                            'scene_type' => 'payoff',
                            'voiceover_segment' => 'Chiudi sul payoff.',
                            'CTA_role' => 'soft_close',
                            'text_overlay' => [
                                'text' => 'Payoff',
                                'position' => 'lower_left',
                                'safe_area' => 'lower_third',
                                'avoid_regions' => ['center_face_zone'],
                            ],
                        ],
                        [
                            'scene_index' => 4,
                            'scene_type' => 'cta',
                            'voiceover_segment' => 'Scrivici ora.',
                            'CTA_role' => 'final_cta',
                            'text_overlay' => [
                                'text' => 'Scrivici ora',
                                'position' => 'lower_center',
                                'safe_area' => 'lower_third',
                                'avoid_regions' => ['center_face_zone'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $run = GenerationRun::create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $item->id,
            'content_plan_id' => $plan->id,
            'run_key' => 'quality-storyboard-run',
            'scope' => 'content_item',
            'trigger_source' => 'job',
            'status' => 'succeeded',
            'format' => 'reel',
            'platform' => 'instagram',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'completed_at' => now(),
        ]);

        $scorecard = app(GenerationQualityScorecardService::class)->buildForContentItem($item, $run);

        $this->assertSame('validated', data_get($scorecard, 'score_sources.storyboard_review.mode'));
        $this->assertTrue((bool) data_get($scorecard, 'storyboard_review.hook_scene_present'));
        $this->assertTrue((bool) data_get($scorecard, 'storyboard_review.cta_scene_present'));
        $this->assertTrue((bool) data_get($scorecard, 'storyboard_review.safe_areas_valid'));
        $this->assertTrue((bool) data_get($scorecard, 'storyboard_review.identity_safe'));
    }

    private function bootstrapTenant(string $slug): array
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Quality',
            'slug' => $slug,
        ]);

        $user = User::factory()->create();
        $user->tenant_id = $tenant->id;
        $user->save();

        TenantProfile::create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Do Mori',
            'industry' => 'Ristorante',
            'services' => 'Cucina veneziana',
            'target' => 'Turisti e residenti',
            'cta' => 'Prenota ora.',
            'default_tone' => 'caldo',
        ]);

        return [$tenant, $user];
    }

    private function createPlan(Tenant $tenant, User $user): ContentPlan
    {
        return ContentPlan::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'Quality Plan',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'status' => 'draft',
            'settings' => [],
            'strategy' => [],
        ]);
    }

    private function createContentItem(Tenant $tenant, User $user, ContentPlan $plan, array $overrides = []): ContentItem
    {
        return ContentItem::create(array_merge([
            'tenant_id' => $tenant->id,
            'content_plan_id' => $plan->id,
            'created_by' => $user->id,
            'platform' => 'instagram',
            'format' => 'post',
            'scheduled_at' => now()->addDay(),
            'status' => 'draft',
            'title' => 'Quality item',
            'caption' => null,
            'hashtags' => [],
            'assets' => [],
            'ai_status' => 'queued',
            'ai_meta' => [],
        ], $overrides));
    }
}
