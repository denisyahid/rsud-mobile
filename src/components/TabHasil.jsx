import React, { useState } from 'react';
import TabLab from './TabLab';
import TabRad from './TabRad';

/**
 * Tab "Hasil Pemeriksaan" — menggabungkan Laboratorium & Radiologi
 * dalam satu tombol bottom, dengan tab pilihan di dalamnya.
 */
export default function TabHasil({ labOrders, radOrders }) {
  const [sub, setSub] = useState('lab'); // 'lab' | 'rad'

  return (
    <div className="space-y-3">
      {/* ─── Header + Tab Pilihan ─────────────────────────────────── */}
      <div className="card p-4 anim-fade-up">
        <h2 className="text-lg font-bold text-gray-800 flex items-center gap-2">
          <i className="fas fa-file-medical-alt text-green-600"></i>
          Hasil Pemeriksaan
        </h2>
        <p className="text-xs text-gray-500 mt-0.5">
          Unduh hasil pemeriksaan laboratorium &amp; radiologi Anda dalam format PDF.
        </p>

        {/* Segmented control: Laboratorium | Radiologi */}
        <div className="flex bg-gray-100 rounded-lg p-0.5 mt-3">
          <button
            onClick={() => setSub('lab')}
            className={`flex-1 flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-md transition ${
              sub === 'lab' ? 'bg-white text-green-700 shadow-sm' : 'text-gray-500'
            }`}
          >
            <i className="fas fa-flask mr-1"></i> Laboratorium
          </button>
          <button
            onClick={() => setSub('rad')}
            className={`flex-1 flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-md transition ${
              sub === 'rad' ? 'bg-white text-green-700 shadow-sm' : 'text-gray-500'
            }`}
          >
            <i className="fas fa-x-ray mr-1"></i> Radiologi
          </button>
        </div>
      </div>

      {/* ─── Isi sesuai pilihan ───────────────────────────────────── */}
      <div key={sub} className="anim-fade">
        {sub === 'lab' ? (
          <TabLab orders={labOrders} />
        ) : (
          <TabRad orders={radOrders} />
        )}
      </div>
    </div>
  );
}
