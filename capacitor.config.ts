import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'com.rsudmalangbong.mobile',
  appName: 'RSUD Malangbong',
  webDir: 'dist',
  // Semua request API memakai https://.../tm/rsud/api.php (absolut),
  // sehingga tidak perlu konfigurasi server khusus di sini.
  android: {
    allowMixedContent: false, // API sudah HTTPS — tidak izinkan konten campuran
    backgroundColor: '#14521A', // sama dengan warna anti-flash index.html
  },
  plugins: {
    // Splash native singkat + berwarna sama dengan splash React → transisi mulus
    SplashScreen: {
      launchShowDuration: 600,
      launchAutoHide: true,
      backgroundColor: '#1B5E20',
      showSpinner: false,
      splashFullScreen: true,
      splashImmersive: true,
    },
  },
};

export default config;
