# ── Capacitor / Bridge ─────────────────────────────────────────────
# Jangan minify kelas Capacitor (bridge dipanggil via refleksi dari JS)
-keep class com.getcapacitor.** { *; }
-keep class com.getcapacitor.cordova.** { *; }
-keep class org.apache.cordova.** { *; }

# Plugin yang didaftarkan via anotasi @CapacitorPlugin
-keep @com.getcapacitor.annotation.CapacitorPlugin class * { *; }
-keepclassmembers class * {
    @com.getcapacitor.annotation.CapacitorPlugin *;
    @com.getcapacitor.annotation.PermissionCallback *;
    @com.getcapacitor.annotation.ActivityCallback *;
}

# Kelas aplikasi (MainActivity, dll.)
-keep class com.rsudmalangbong.mobile.** { *; }

# Keep nama field JSON (JSObject) tidak diubah
-keepclassmembers class com.getcapacitor.JSObject { *; }
