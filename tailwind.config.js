/* Farben kommen aus CSS-Variablen (Whitelabel, siehe /brand/theme.css). */
const STEPS = ['50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950'];
const COLORS = (n) => Object.fromEntries(STEPS.map((s) => [s, `rgb(var(--${n}-${s}) / <alpha-value>)`]));

module.exports = {
  content: ['./templates/**/*.php', './public/assets/*.js', './public/setup.php', './src/**/*.php'],
  darkMode: ['selector', '[data-theme="dark"]'],
  theme: {
    extend: {
      fontFamily: { sans: ['var(--font-sans)'] },
      colors: {
        brand: COLORS('brand'),
        accent: COLORS('accent'),
        slate: COLORS('n'),
      },
      borderRadius: { lg: 'var(--r-lg)', xl: 'var(--r-xl)', '2xl': 'var(--r-2xl)' },
      keyframes: {
        'fade-up': { '0%': { opacity: '0', transform: 'translateY(14px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
        'float': { '0%,100%': { transform: 'translate3d(0,0,0)' }, '50%': { transform: 'translate3d(0,-18px,0)' } },
      },
      animation: { 'fade-up': 'fade-up .5s ease-out both', float: 'float 14s ease-in-out infinite' },
    },
  },
  plugins: [],
};
