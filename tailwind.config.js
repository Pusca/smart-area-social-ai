import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Plus Jakarta Sans', ...defaultTheme.fontFamily.sans],
                heading: ['Sora', 'Plus Jakarta Sans', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                bg: 'var(--bg)',
                surface: 'var(--surface)',
                'surface-2': 'var(--surface-2)',
                text: 'var(--text)',
                muted: 'var(--text-muted)',
                border: 'var(--border)',
                primary: 'var(--primary)',
                'primary-600': 'var(--primary-600)',
                accent: 'var(--accent)',
                success: 'var(--success)',
                warning: 'var(--warning)',
                danger: 'var(--danger)',
                info: 'var(--info)',
            },
            boxShadow: {
                card: 'var(--shadow-sm)',
                panel: 'var(--shadow-md)',
                focus: 'var(--focus-ring)',
            },
            backgroundImage: {
                gradient: 'var(--gradient)',
            },
            borderRadius: {
                sm: 'var(--radius-sm)',
                md: 'var(--radius-md)',
                lg: 'var(--radius-lg)',
                xl: 'var(--radius-xl)',
            },
        },
    },

    plugins: [forms],
};
