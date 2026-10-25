import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
    ],

    darkMode: 'class',

    theme: {
        extend: {
            colors: {
                // ── Surfaces: deep purple-black, matching the app's dark UI ──
                surface: {
                    DEFAULT: '#0B0410',
                    raised: '#150A1C',
                    overlay: '#1D0E26',
                    border: '#2C1738',
                },

                // ── Ink (text) ────────────────────────────────────────────────
                ink: {
                    DEFAULT: '#F4EDFA',
                    muted: '#A694B5',
                    faint: '#6E5C7E',
                },

                // ── Primary: the hot pink/magenta accent from the designs ────
                brand: {
                    DEFAULT: '#E91E8C',
                    soft: '#F45BA4',
                    deep: '#B0156C',
                },

                // ── Secondary: the purple used for gradients & chips ────────
                grape: {
                    DEFAULT: '#7C3AED',
                    soft: '#A78BFA',
                    deep: '#5B21B6',
                },

                // ── Cash: gold and the "money green" for balances ───────────
                cash: {
                    DEFAULT: '#F5B301',
                    soft: '#FFD24A',
                    deep: '#C98600',
                },

                // ── Success: the green used for withdraw buttons ────────────
                success: {
                    DEFAULT: '#22C55E',
                    soft: '#4ADE80',
                    deep: '#15803D',
                },

                // ── Status ────────────────────────────────────────────────────
                danger: {
                    DEFAULT: '#EF4444',
                    soft: '#F87171',
                },

                warn: {
                    DEFAULT: '#F59E0B',
                    soft: '#FBBF24',
                },
            },

            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                display: ['Poppins', 'Inter', ...defaultTheme.fontFamily.sans],
            },

            borderRadius: {
                '4xl': '2rem',
            },

            boxShadow: {
                glow: '0 0 24px -4px rgba(233, 30, 140, 0.45)',
                'glow-lg': '0 0 48px -6px rgba(233, 30, 140, 0.55)',
                card: '0 4px 24px -6px rgba(0, 0, 0, 0.6)',
            },

            backgroundImage: {
                'brand-gradient': 'linear-gradient(135deg, #E91E8C 0%, #7C3AED 100%)',
                'cash-gradient': 'linear-gradient(135deg, #FFD24A 0%, #F5B301 100%)',
                'surface-gradient': 'linear-gradient(180deg, #150A1C 0%, #0B0410 100%)',
                'hero-gradient': 'linear-gradient(180deg, rgba(11,4,16,0) 0%, rgba(11,4,16,0.95) 100%)',
            },

            keyframes: {
                'fade-up': {
                    '0%': { opacity: '0', transform: 'translateY(12px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                'pop-in': {
                    '0%': { opacity: '0', transform: 'scale(0.85)' },
                    '60%': { transform: 'scale(1.05)' },
                    '100%': { opacity: '1', transform: 'scale(1)' },
                },
            },

            animation: {
                'fade-up': 'fade-up 0.35s ease-out both',
                'pop-in': 'pop-in 0.3s ease-out both',
            },
        },
    },

    plugins: [],
};
