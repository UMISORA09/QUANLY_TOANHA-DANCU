import React, { useState } from 'react';
import {
  FolderTree,
  Eye,
  EyeOff,
  Crosshair,
  Building2,
  Layers,
  Waves,
  Car,
  TreePine,
  Sun,
  Search,
  CheckCircle2,
  X
} from 'lucide-react';
import { A3DOutlinerItem } from './types';

interface A3DSceneOutlinerProps {
  items: A3DOutlinerItem[];
  onToggleVisibility: (id: string) => void;
  onFocusItem: (item: A3DOutlinerItem) => void;
  onClose: () => void;
}

const CATEGORY_NAMES: Record<A3DOutlinerItem['category'], { title: string; color: string }> = {
  tower: { title: 'Khối Tháp Chính (Towers)', color: 'text-sky-400' },
  podium: { title: 'Khối Đế & Thương Mại (Podium)', color: 'text-indigo-400' },
  amenity: { title: 'Tiện Ích Cao Cấp (Amenities)', color: 'text-cyan-400' },
  basement: { title: 'Hạ Tầng Ngầm & Bãi Xe (Basement)', color: 'text-amber-400' },
  landscape: { title: 'Cảnh Quan & Giao Thông (Landscape)', color: 'text-emerald-400' },
  lighting: { title: 'Môi Trường & Chiếu Sáng (Studio Env)', color: 'text-yellow-400' },
};

export const A3DSceneOutliner: React.FC<A3DSceneOutlinerProps> = ({
  items,
  onToggleVisibility,
  onFocusItem,
  onClose,
}) => {
  const [search, setSearch] = useState('');

  const filteredItems = items.filter((item) =>
    item.name.toLowerCase().includes(search.toLowerCase()) ||
    (item.description && item.description.toLowerCase().includes(search.toLowerCase()))
  );

  const categories = Array.from(new Set(filteredItems.map((i) => i.category)));

  const getIcon = (category: A3DOutlinerItem['category']) => {
    switch (category) {
      case 'tower':
        return <Building2 className="w-3.5 h-3.5 text-sky-400" />;
      case 'podium':
        return <Layers className="w-3.5 h-3.5 text-indigo-400" />;
      case 'amenity':
        return <Waves className="w-3.5 h-3.5 text-cyan-400" />;
      case 'basement':
        return <Car className="w-3.5 h-3.5 text-amber-400" />;
      case 'landscape':
        return <TreePine className="w-3.5 h-3.5 text-emerald-400" />;
      case 'lighting':
        return <Sun className="w-3.5 h-3.5 text-yellow-400" />;
    }
  };

  return (
    <div className="w-84 max-h-[580px] flex flex-col rounded-3xl bg-slate-900/95 backdrop-blur-2xl border border-white/10 shadow-2xl overflow-hidden animate-in fade-in slide-in-from-left-2">
      {/* Header */}
      <div className="p-3.5 border-b border-white/10 flex items-center justify-between bg-slate-950/40">
        <div className="flex items-center gap-2">
          <div className="p-1.5 rounded-xl bg-sky-500/20 text-sky-400 border border-sky-500/30">
            <FolderTree className="w-4 h-4" />
          </div>
          <div>
            <h4 className="text-xs font-black text-white tracking-wide uppercase font-mono">
              Scene Outliner
            </h4>
            <p className="text-[10px] text-slate-400">Cây phân cấp đối tượng 3D kiến trúc</p>
          </div>
        </div>
        <button
          type="button"
          onClick={onClose}
          className="p-1.5 rounded-xl text-slate-400 hover:text-white hover:bg-white/10 transition-all cursor-pointer"
        >
          <X className="w-4 h-4" />
        </button>
      </div>

      {/* Search box */}
      <div className="p-2.5 border-b border-white/5 bg-white/[0.02]">
        <div className="relative flex items-center">
          <Search className="w-3.5 h-3.5 absolute left-2.5 text-slate-400 pointer-events-none" />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Tìm kiếm khối kiến trúc..."
            className="w-full pl-8 pr-3 py-1.5 rounded-xl bg-slate-950/60 border border-white/10 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-sky-500/60 font-sans"
          />
        </div>
      </div>

      {/* Outliner tree content */}
      <div className="flex-1 overflow-y-auto p-2.5 space-y-3.5 max-h-[440px]">
        {categories.map((cat) => {
          const catItems = filteredItems.filter((i) => i.category === cat);
          const meta = CATEGORY_NAMES[cat];
          return (
            <div key={cat} className="space-y-1">
              <div className="px-2 py-1 flex items-center justify-between text-[10px] font-mono font-bold uppercase tracking-wider text-slate-400 border-b border-white/5">
                <span className={meta.color}>{meta.title}</span>
                <span className="text-slate-500">{catItems.length}</span>
              </div>

              <div className="space-y-1 pt-0.5">
                {catItems.map((item) => (
                  <div
                    key={item.id}
                    className={`group flex items-center justify-between p-2 rounded-xl transition-all border ${
                      item.visible
                        ? 'bg-white/[0.03] border-white/5 hover:bg-white/[0.08] hover:border-white/10'
                        : 'bg-white/[0.01] border-transparent opacity-50 hover:opacity-75'
                    }`}
                  >
                    <div
                      className="flex items-center gap-2 min-w-0 cursor-pointer flex-1 mr-2"
                      onClick={() => onFocusItem(item)}
                      title="Nhấp để focus camera vào đối tượng"
                    >
                      {getIcon(item.category)}
                      <div className="min-w-0 flex-1">
                        <div className="text-xs font-semibold text-slate-200 truncate group-hover:text-sky-300 transition-colors">
                          {item.name}
                        </div>
                        {item.description && (
                          <div className="text-[10px] text-slate-500 truncate leading-tight">
                            {item.description}
                          </div>
                        )}
                      </div>
                    </div>

                    <div className="flex items-center gap-1 shrink-0">
                      <button
                        type="button"
                        onClick={() => onFocusItem(item)}
                        title="Bay camera tới đối tượng"
                        className="p-1 rounded-lg text-slate-400 hover:text-sky-400 hover:bg-sky-500/20 transition-all cursor-pointer"
                      >
                        <Crosshair className="w-3.5 h-3.5" />
                      </button>
                      <button
                        type="button"
                        onClick={() => onToggleVisibility(item.id)}
                        title={item.visible ? 'Ẩn đối tượng' : 'Hiện đối tượng'}
                        className={`p-1 rounded-lg transition-all cursor-pointer ${
                          item.visible
                            ? 'text-sky-400 hover:bg-sky-500/20'
                            : 'text-slate-500 hover:text-slate-300 hover:bg-white/10'
                        }`}
                      >
                        {item.visible ? <Eye className="w-3.5 h-3.5" /> : <EyeOff className="w-3.5 h-3.5" />}
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
};
