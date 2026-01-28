<x-app-layout>
    <div class="py-6">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900">Modifica contenuto</h1>
                    <p class="mt-1 text-sm text-gray-600">Aggiorna titolo, caption e programmazione.</p>
                </div>
                <a href="{{ route('posts') }}"
                   class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    ← Indietro
                </a>
            </div>

            <form method="POST" action="{{ route('posts.update', $item) }}" class="mt-6 space-y-4">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-sm font-medium text-gray-700">Piattaforma</label>
                        <select name="platform" class="mt-1 w-full rounded-xl border-gray-200">
                            <option value="instagram" @selected($item->platform==='instagram')>Instagram</option>
                            <option value="facebook" @selected($item->platform==='facebook')>Facebook</option>
                            <option value="tiktok" @selected($item->platform==='tiktok')>TikTok</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-sm font-medium text-gray-700">Formato</label>
                        <select name="format" class="mt-1 w-full rounded-xl border-gray-200">
                            <option value="post" @selected($item->format==='post')>Post</option>
                            <option value="reel" @selected($item->format==='reel')>Reel</option>
                            <option value="story" @selected($item->format==='story')>Story</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-sm font-medium text-gray-700">Stato</label>
                        <select name="status" class="mt-1 w-full rounded-xl border-gray-200">
                            <option value="draft" @selected($item->status==='draft')>draft</option>
                            <option value="review" @selected($item->status==='review')>review</option>
                            <option value="approved" @selected($item->status==='approved')>approved</option>
                            <option value="scheduled" @selected($item->status==='scheduled')>scheduled</option>
                            <option value="published" @selected($item->status==='published')>published</option>
                            <option value="failed" @selected($item->status==='failed')>failed</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-sm font-medium text-gray-700">Programma (opzionale)</label>
                        <input type="datetime-local" name="scheduled_at"
                               value="{{ $item->scheduled_at ? \Illuminate\Support\Carbon::parse($item->scheduled_at)->format('Y-m-d\TH:i') : '' }}"
                               class="mt-1 w-full rounded-xl border-gray-200" />
                    </div>
                </div>

                <div>
                    <label class="text-sm font-medium text-gray-700">Titolo</label>
                    <input type="text" name="title" value="{{ $item->title }}" class="mt-1 w-full rounded-xl border-gray-200" />
                </div>

                <div>
                    <label class="text-sm font-medium text-gray-700">Caption</label>
                    <textarea name="caption" rows="6" class="mt-1 w-full rounded-xl border-gray-200">{{ $item->caption }}</textarea>
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                        Salva modifiche
                    </button>
                    <a href="{{ route('calendar') }}" class="rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Vai al calendario
                    </a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
