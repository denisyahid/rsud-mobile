package com.rsudmalangbong.mobile;

import android.os.Bundle;
import android.webkit.CookieManager;
import android.webkit.WebView;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        // Izinkan cookie pihak ketiga di dalam WebView.
        // Diperlukan karena aplikasi memuat aset lokal (https://localhost)
        // tetapi session login disimpan sebagai cookie dari domain API
        // (https://...ts.net/tm/rsud/api.php). Tanpa ini, pengguna harus
        // login ulang setiap membuka aplikasi.
        WebView webView = getBridge().getWebView();
        if (webView != null) {
            CookieManager.getInstance().setAcceptThirdPartyCookies(webView, true);
        }
    }
}
