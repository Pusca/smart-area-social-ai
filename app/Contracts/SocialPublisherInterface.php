<?php

namespace App\Contracts;

use App\Models\SocialAccount;
use App\Models\SocialPublication;

/**
 * Contratto per tutti gli adapter di pubblicazione social.
 *
 * Ogni piattaforma implementa questo contratto: MetaGraphService,
 * LinkedInApiService, TikTokApiService, GoogleBusinessApiService.
 *
 * Il SocialPublisherRegistry usa questo contratto per dispatchare
 * la pubblicazione al servizio corretto basandosi su $account->provider.
 */
interface SocialPublisherInterface
{
    /**
     * Pubblica un contenuto su una piattaforma social.
     *
     * @param  SocialAccount      $account      Account social a cui pubblicare.
     * @param  SocialPublication  $publication  Record di pubblicazione con caption, media_url, payload.
     *
     * @return array{
     *   remote_id: string|null,
     *   remote_url: string|null,
     *   meta: array<string, mixed>
     * }
     *
     * @throws \RuntimeException Se la pubblicazione fallisce.
     */
    public function publish(SocialAccount $account, SocialPublication $publication): array;
}
