/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        erpBg: '#F8FAFC',
        erpBorder: '#E2E8F0', // slate-200
      },
    },
  },
  plugins: [],
}