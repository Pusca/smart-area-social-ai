<?php

namespace Tests\Unit;

use App\Support\ImagePromptRealismGuard;
use PHPUnit\Framework\TestCase;

class ImagePromptRealismGuardTest extends TestCase
{
    public function test_it_forces_photorealism_by_default_for_business_content(): void
    {
        $this->assertTrue(ImagePromptRealismGuard::shouldForcePhotorealism(
            'Annuncia l inaugurazione del ristorante',
            'Interno elegante del ristorante con clienti sorridenti'
        ));
    }

    public function test_it_detects_explicit_creative_style_requests(): void
    {
        $this->assertFalse(ImagePromptRealismGuard::shouldForcePhotorealism(
            'Crea una illustrazione cartoon del locale',
            'Poster illustrato in stile manga'
        ));
    }

    public function test_it_sanitizes_stylized_people_language_when_realism_is_forced(): void
    {
        $sanitized = ImagePromptRealismGuard::sanitize(
            'Interno del ristorante con clienti stilizzati e sorridenti, stile illustrativo',
            true
        );

        $this->assertStringNotContainsString('stilizzati', $sanitized);
        $this->assertStringNotContainsString('illustrativo', $sanitized);
        $this->assertStringContainsString('clienti reali e naturali', $sanitized);
        $this->assertStringContainsString('stile fotografico realistico', $sanitized);
    }
}
