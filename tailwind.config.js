import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    safelist: [
        {
            pattern: /grid-cols-(1|2|3|4|5|6|7|8|9|10|11|12)/,
            variants: ['sm', 'md', 'lg', 'xl'],
        },
        {
            pattern: /col-span-(1|2|3|4|5|6|7|8|9|10|11|12|full)/,
            variants: ['sm', 'md', 'lg', 'xl'],
        },
        {
            pattern: /row-span-(1|2|3|4|5|6|7|8|9|full)/,
            variants: ['sm', 'md', 'lg', 'xl'],
        },
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                cream: {
                    50: '#fdfbf7',
                    100: '#faf6ea',
                    200: '#faf4df',
                    300: '#f5ecd5',
                    400: '#ebe1c3',
                    500: '#ddd0ab',
                },
                forest: {
                    50: '#f0fdf4',
                    100: '#dcfce7',
                    200: '#bbf7d0',
                    400: '#4ade80',
                    500: '#22c55e',
                    600: '#16a34a',
                    700: '#15803d',
                    800: '#166534',
                    900: '#1b4332',
                    950: '#0f2e1e',
                },
                golden: {
                    100: '#fef3c7',
                    200: '#fde68a',
                    300: '#fde047',
                    400: '#f0d078',
                    500: '#f4b841',
                    600: '#eab308',
                },
            },
        },
    },

    plugins: [forms, typography],
};
