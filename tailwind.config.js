import forms from '@tailwindcss/forms';
import containerQueries from '@tailwindcss/container-queries';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{vue,js}',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'sans-serif'],
                mono: ['JetBrains Mono', 'monospace'],
            },
            colors: {
                hospital: {
                    50: '#f0f9ff',
                    100: '#e0f2fe',
                    500: '#0284c7',
                    600: '#0369a1',
                    700: '#075985',
                    800: '#0c4a6e',
                },
                ewsCritical: '#dc2626',
                ewsWarning: '#ea580c',
                ewsNormal: '#16a34a',
            },
        },
    },
    plugins: [forms, containerQueries],
};
