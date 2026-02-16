<x-app-layout>
    <div class="py-6">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900">Nuovo contenuto</h1>
                    <p class="mt-1 text-sm text-gray-600">Crea una bozza o pianifica una pubblicazione.</p>
                </div>
                <a href="{{ route('posts.index') }}"
                   class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    ← Indietro
                </a>
            </div>

            <form method="POST" action="{{ route('posts.store') }}" class="mt-6 space-y-4">
                @csrf

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-sm font-medium text-gray-700">Piattaforma</label>
                        <select name="platform" class="mt-1 w-full rounded-xl border-gray-200">
                            <option value="instagram">Instagram</option>
                            <option value="facebook">Facebook</option>
                            <option value="tiktok">TikTok</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-sm font-medium text-gray-700">Formato</label>
                        <select name="format" class="mt-1 w-full rounded-xl border-gray-200">
                            <option value="post">Post</option>
                            <option value="reel">Reel</option>
                            <option value="story">Story</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-sm font-medium text-gray-700">Stato</label>
                        <select name="status" class="mt-1 w-full rounded-xl border-gray-200">
                            <option value="draft">draft</option>
                            <option value="review">review</option>
                            <option value="approved">approved</option>
                            <option value="scheduled">scheduled</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-sm font-medium text-gray-700">Programma (opzionale)</label>
                        <input type="datetime-local" name="scheduled_at" class="mt-1 w-full rounded-xl border-gray-200" />
                    </div>
                </div>

                <div>
                    <label class="text-sm font-medium text-gray-700">Titolo</label>
                    <input type="text" name="title" class="mt-1 w-full rounded-xl border-gray-200" placeholder="Es. Reel: 3 tips per..." />
                </div>

                <div>
                    <label class="text-sm font-medium text-gray-700">Caption</label>
                    <textarea name="caption" rows="6" class="mt-1 w-full rounded-xl border-gray-200" placeholder="Scrivi la caption..."></textarea>
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                        Salva
                    </button>
                    <a href="{{ route('calendar') }}" class="rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Vai al calendario
                    </a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
