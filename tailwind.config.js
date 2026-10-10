/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
    ],
    theme: {
        extend: {
            boxShadow: {
                glass: '0 8px 32px 0 rgba(31, 38, 135, 0.07)',
            },
            colors: {
                brand: {
                    50: '#f4f1fb',
                    100: '#e8e4f8',
                    500: '#5145dc',
                    600: '#4233cd',
                    700: '#3c2db8',
                },
            },
        },
    },
    plugins: [],
};
