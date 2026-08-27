import React, { useState } from 'react';

export default function ProfileCard({ profile, onLogout }) {
  const [imgError, setImgError] = useState(false);
  if (!profile) return null;

  return (
    <div className="profile-bar">
      {/* Logo RSUD */}
      <div className="profile-bar-logo">
        {imgError ? (
          <i className="fas fa-hospital-user profile-bar-logo-fallback" aria-hidden="true"></i>
        ) : (
          <img
            src="./logo.png"
            alt="Logo RSUD Malangbong"
            onError={() => setImgError(true)}
          />
        )}
      </div>

      {/* Nama + RM / umur / JK */}
      <div className="flex-1 min-w-0">
        <h2 className="profile-name truncate">{profile.namapasien}</h2>
        <p className="profile-meta">
          <span>
            <i className="far fa-id-card"></i> RM {profile.nocm}
          </span>
          {profile.umur && profile.umur !== '-' && (
            <>
              <span className="profile-dot">•</span>
              {profile.umur}
            </>
          )}
          {profile.jenis_kelamin && profile.jenis_kelamin !== '-' && (
            <>
              <span className="profile-dot">•</span>
              {profile.jenis_kelamin}
            </>
          )}
        </p>
      </div>

      {/* Aksi */}
      <button onClick={onLogout} className="profile-icon-btn" aria-label="Keluar" title="Keluar">
        <i className="fas fa-sign-out-alt"></i>
      </button>
    </div>
  );
}
