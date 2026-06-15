<x-app-layout>
    <section class="ui-shell ui-page space-y-5">
        <div class="ui-card p-6">
            <div class="ui-kicker">Account</div>
            <h1 class="mt-2 ui-title-xl">{{ __('Profile') }}</h1>
            <p class="mt-2 ui-subtitle">Aggiorna dati account, password e impostazioni di sicurezza.</p>
        </div>

        <div class="space-y-5">
            <div class="ui-card p-5 sm:p-7">
                <div class="max-w-xl">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </div>

            <div class="ui-card p-5 sm:p-7">
                <div class="max-w-xl">
                    @include('profile.partials.update-password-form')
                </div>
            </div>

            <div class="ui-card p-5 sm:p-7">
                <div class="max-w-xl">
                    @include('profile.partials.delete-user-form')
                </div>
            </div>
        </div>
    </section>
</x-app-layout>
