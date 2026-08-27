import React, { useState } from 'react';

export default function SplashScreen({ message = 'Memuat data...' }) {
  const [imgError, setImgError] = useState(false);

  return (
    <div className="splash-screen">
      <div className="splash-inner">
        <div className="splash-logo-wrap anim-pop">
          {imgError ? (
            <i className="fas fa-hospital-user splash-fallback" />
          ) : (
            <img
              src="./logo.png"
              alt="Logo RSUD Malangbong"
              onError={() => setImgError(true)}
            />
          )}
        </div>
        <h1 className="splash-title">RSUD Malangbong</h1>
        <p className="splash-sub">Aplikasi Mobile Pasien</p>
        <div className="m3-progress" role="progressbar" aria-label="Memuat" />
        <p className="splash-msg">{message}</p>
      </div>
    </div>
  );
}
