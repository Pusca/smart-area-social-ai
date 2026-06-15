import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;
Alpine.start();

if ('serviceWorker' in navigator) {
    const isLocalHost = ['localhost', '127.0.0.1', '[::1]'].includes(window.location.hostname);
    const shouldRegisterSw = import.meta.env.PROD && !isLocalHost;

    window.addEventListener('load', async () => {
        if (shouldRegisterSw) {
            try {
                await navigator.serviceWorker.register('/sw.js', { scope: '/' });
            } catch (error) {
                console.error('Service Worker registration failed:', error);
            }
            return;
        }

        // In locale evitiamo cache SW stale che possono causare caricamenti anomali.
        try {
            const registrations = await navigator.serviceWorker.getRegistrations();
            await Promise.all(registrations.map((registration) => registration.unregister()));

            if ('caches' in window) {
                const keys = await window.caches.keys();
                await Promise.all(keys.map((key) => window.caches.delete(key)));
            }
        } catch (_) {
            // no-op
        }
    });
}

function initVideoFramePreview(root = document) {
    root.querySelectorAll('video[data-frame-preview]').forEach((video) => {
        if (video.dataset.framePreviewBound === '1') {
            return;
        }
        video.dataset.framePreviewBound = '1';

        const seekToPreviewFrame = () => {
            try {
                const duration = Number.isFinite(video.duration) ? video.duration : 0;
                const target = Math.min(0.08, Math.max(0.01, duration > 0 ? duration * 0.01 : 0.02));
                video.currentTime = target;
            } catch (_) {
                // no-op
            }
        };

        const freezeFrame = () => {
            try {
                video.pause();
            } catch (_) {
                // no-op
            }
        };

        video.addEventListener('loadedmetadata', seekToPreviewFrame, { once: true });
        video.addEventListener('loadeddata', seekToPreviewFrame, { once: true });
        video.addEventListener('canplay', seekToPreviewFrame, { once: true });
        video.addEventListener('seeked', freezeFrame, { once: true });
        video.addEventListener('error', freezeFrame, { once: true });

        if (video.readyState >= 1) {
            seekToPreviewFrame();
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initVideoFramePreview(document);
});
