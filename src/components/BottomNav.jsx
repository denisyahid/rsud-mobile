import React from 'react';

export default function BottomNav({ activeTab, onTabChange }) {
  const tabs = [
    { id: 'informasi', icon: 'fa-info-circle', label: 'Informasi' },
    { id: 'jadwal', icon: 'fa-calendar-check', label: 'Reservasi' },
    { id: 'hasil', icon: 'fa-file-medical-alt', label: 'Hasil' },
    { id: 'riwayat', icon: 'fa-history', label: 'Riwayat' },
  ];

  return (
    <nav className="bottom-bar bottom-nav" aria-label="Navigasi utama">
      {tabs.map(tab => {
        const active = activeTab === tab.id;
        return (
          <button
            key={tab.id}
            className={`nav-item ${active ? 'active' : ''}`}
            onClick={() => onTabChange(tab.id)}
            aria-label={tab.label}
          >
            <span className="nav-pill" aria-hidden="true"></span>
            <i className={`fas ${tab.icon}`} aria-hidden="true"></i>
            <span className="nav-label">{tab.label}</span>
          </button>
        );
      })}
    </nav>
  );
}
