<?php

namespace App\Services\Social;

use App\Contracts\SocialPublisherInterface;
use App\Models\SocialAccount;
use App\Models\SocialPublication;
use RuntimeException;

/**
 * Registry centrale per i publisher social.
 *
 * Risolve il servizio corretto in base al campo `provider` dell'account
 * e delega la pubblicazione. Disaccoppia il Job di pubblicazione dalla
 * conoscenza dei singoli adapter.
 *
 * Mapping provider → SocialPublisherInterface:
 *   meta      → MetaGraphService    (Instagram + Facebook)
 *   linkedin  → LinkedInApiService
 *   tiktok    → TikTokApiService
 *   google    → GoogleBusinessApiService
 *
 * Aggiungere un nuovo provider:
 *   1. Creare il servizio che implementa SocialPublisherInterface
 *   2. Aggiungere il mapping in PROVIDER_MAP
 *   3. Registrare il servizio nel container se ha dipendenze
 */
class SocialPublisherRegistry
{
    /**
     * Mappa provider → classe concreta.
     * Deve corrispondere ai valori di SocialAccount::provider.
     *
     * @var array<string, class-string<SocialPublisherInterface>>
     */
    private const PROVIDER_MAP = [
        'meta'     => MetaGraphService::class,
        'linkedin' => LinkedInApiService::class,
        'tiktok'   => TikTokApiService::class,
        'google'   => GoogleBusinessApiService::class,
    ];

    /**
     * Pubblica un contenuto usando il publisher corretto per il provider dell'account.
     *
     * @return array{remote_id:string|null,remote_url:string|null,meta:array<string,mixed>}
     *
     * @throws RuntimeException Se il provider non è supportato.
     */
    public function publish(SocialAccount $account, SocialPublication $publication): array
    {
        $publisher = $this->resolve($account);

        return $publisher->publish($account, $publication);
    }

    /**
     * Risolve il publisher per un account, usando il container Laravel per l'injection.
     *
     * @throws RuntimeException Se il provider non ha un adapter registrato.
     */
    public function resolve(SocialAccount $account): SocialPublisherInterface
    {
        $provider = strtolower(trim((string) ($account->provider ?? '')));

        if (!isset(self::PROVIDER_MAP[$provider])) {
            throw new RuntimeException(
                "Nessun publisher registrato per il provider '{$provider}'. "
                . 'Provider supportati: ' . implode(', ', array_keys(self::PROVIDER_MAP)) . '.'
            );
        }

        /** @var SocialPublisherInterface $publisher */
        $publisher = app(self::PROVIDER_MAP[$provider]);

        return $publisher;
    }

    /**
     * Restituisce true se esiste un adapter per il provider dato.
     */
    public function supports(string $provider): bool
    {
        return isset(self::PROVIDER_MAP[strtolower(trim($provider))]);
    }

    /**
     * Elenca tutti i provider supportati.
     *
     * @return array<int, string>
     */
    public function supportedProviders(): array
    {
        return array_keys(self::PROVIDER_MAP);
    }
}
