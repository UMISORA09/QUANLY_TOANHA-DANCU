import React, { useState } from 'react';
import {
  Wand2,
  Download,
  Camera,
  Layers,
  Sparkles,
  Copy,
  Check,
  ExternalLink,
  Code2,
  X
} from 'lucide-react';
import { A3DAiPromptPreset } from './types';

interface A3DAiRenderStudioProps {
  onCaptureSnapshot: () => void;
  onCaptureDepthMap: () => void;
  onSetShadingMode: (mode: 'realistic' | 'clay' | 'wireframe' | 'depth') => void;
  currentShadingMode: 'realistic' | 'clay' | 'wireframe' | 'depth';
  onClose: () => void;
}

const AI_PROMPT_PRESETS: A3DAiPromptPreset[] = [
  {
    id: 'day-ocean',
    title: 'Phối Cảnh Ban Ngày Ven Biển Đà Nẵng',
    tag: 'Chân thực • Kiến trúc 8K',
    promptEn:
      'Ultra-luxury architectural photograph of modern twin towers skyscraper complex in Da Nang coastline, aerodynamic curved glass facade, cantilevered balconies, infinity rooftop pool with tropical palms, turquoise ocean reflection, pristine blue sky, golden sunlight, 8k resolution, architectural digest award winning, octane render quality, photorealistic',
    promptVi:
      'Ảnh chụp kiến trúc cao cấp tổ hợp tháp đôi hiện đại bên bờ biển Đà Nẵng, mặt kính cong Low-E, ban công vươn đón gió, bể bơi vô cực, phản chiếu biển xanh rực rỡ.',
    negativePrompt:
      'blurry, distorted architecture, low resolution, warped lines, bad geometry, oversaturated, amateur photo, cartoon',
    recommendedModel: 'Flux.1 Dev / ControlNet Depth SDXL',
    comfyNodes: 'Load Image (Depth Map) -> Apply ControlNet (Depth) -> KSampler',
  },
  {
    id: 'sunset-coastal',
    title: 'Hoàng Hôn Ánh Vàng Tím Vịnh Biển',
    tag: 'Cinematic • Golden Hour',
    promptEn:
      'Cinematic architectural mastershot of twin skyscraper towers during breathtaking golden hour sunset over coastal bay, warm glowing curtain wall illumination, violet and fiery orange clouds, reflections on calm sea water, professional architectural photography, Hasselblad 100MP, dramatic atmospheric lighting',
    promptVi:
      'Toàn cảnh kiến trúc tháp đôi lúc hoàng hôn vịnh biển lộng lẫy, ánh đèn nội thất vàng ấm phản chiếu qua vách kính, mây tím và cam rực rỡ.',
    negativePrompt: 'dark, noisy, grain, oversaturated, unnatural lighting, artifacts',
    recommendedModel: 'Flux.1 / Midjourney v6 + Depth',
    comfyNodes: 'Depth ControlNet -> Prompts -> LoRA Architecture',
  },
  {
    id: 'cyber-night',
    title: 'Đêm Lung Linh & LED Wall Mặt Dựng',
    tag: 'Futuristic • Night Illumination',
    promptEn:
      'Futuristic luxury architectural night view of twin towers, dynamic digital LED facade wall glowing, illuminated skybridge, glowing infinity pool water, starry night sky with subtle ocean breeze, reflections in landscape pond, architectural visualization, hyperdetailed, 8k',
    promptVi:
      'Góc nhìn đêm hiện đại với màn hình LED kỹ thuật số trên mái vòm, cầu kính phát sáng, bể bơi phản chiếu ánh đèn lung linh.',
    negativePrompt: 'dim, unlit, pixelated, washed out, cartoon, low quality',
    recommendedModel: 'SDXL Lightning / ComfyUI Depth',
    comfyNodes: 'ControlNet Depth -> IP-Adapter Lighting -> Upscale',
  },
  {
    id: 'clay-plaster',
    title: 'Sa Bàn Thạch Cao Kiến Trúc (Clay Model)',
    tag: 'Sa bàn • Tối giản Studio',
    promptEn:
      'Architectural scale model of twin skyscraper towers made of pure white matte plaster and fine teak wood details, exhibited in a high-end minimalist museum gallery, soft studio key lighting, subtle ambient occlusion shadows, macro lens photography, clean lines, depth of field',
    promptVi:
      'Mô hình sa bàn kiến trúc thạch cao trắng mờ kết hợp đế gỗ cao cấp, chụp trong studio ánh sáng bảo tàng tối giản.',
    negativePrompt: 'colors, textures, loud background, messy, clutter',
    recommendedModel: 'Flux.1 Schnell / Trellis AI 3D',
    comfyNodes: 'Clay Shading -> Depth Map -> Ambient Occlusion pass',
  },
];

export const A3DAiRenderStudio: React.FC<A3DAiRenderStudioProps> = ({
  onCaptureSnapshot,
  onCaptureDepthMap,
  onSetShadingMode,
  currentShadingMode,
  onClose,
}) => {
  const [copiedId, setCopiedId] = useState<string | null>(null);
  const [selectedPreset, setSelectedPreset] = useState<A3DAiPromptPreset>(AI_PROMPT_PRESETS[0]);

  const handleCopyPrompt = (preset: A3DAiPromptPreset) => {
    navigator.clipboard.writeText(preset.promptEn);
    setCopiedId(preset.id);
    setTimeout(() => setCopiedId(null), 2500);
  };

  return (
    <div className="w-96 max-h-[620px] flex flex-col rounded-3xl bg-slate-900/95 backdrop-blur-2xl border border-sky-500/30 shadow-2xl overflow-hidden animate-in fade-in slide-in-from-right-2">
      {/* Header */}
      <div className="p-3.5 border-b border-white/10 flex items-center justify-between bg-slate-950/40">
        <div className="flex items-center gap-2">
          <div className="p-1.5 rounded-xl bg-gradient-to-tr from-sky-500 to-indigo-500 text-white shadow-md shadow-sky-500/20">
            <Wand2 className="w-4 h-4" />
          </div>
          <div>
            <h4 className="text-xs font-black text-white tracking-wide uppercase font-mono flex items-center gap-1.5">
              <span>A3D AI Render Studio</span>
              <span className="px-1.5 py-0.2 rounded text-[9px] font-bold bg-amber-500/20 text-amber-400 border border-amber-500/30">
                ComfyUI Ready
              </span>
            </h4>
            <p className="text-[10px] text-slate-400">Kết xuất Depth Map & Gợi ý Prompt AI Kiến Trúc</p>
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

      <div className="p-4 space-y-4 overflow-y-auto max-h-[540px]">
        {/* ================= 1. NÚT KẾT XUẤT NHANH ================= */}
        <div className="space-y-2">
          <div className="text-[11px] font-bold text-slate-300 font-mono uppercase tracking-wider">
            1. Kết Xuất Dữ Liệu 3D
          </div>
          <div className="grid grid-cols-2 gap-2">
            <button
              type="button"
              onClick={onCaptureSnapshot}
              className="flex items-center justify-center gap-2 p-3 rounded-2xl bg-gradient-to-r from-sky-600 to-blue-600 hover:from-sky-500 hover:to-blue-500 text-white text-xs font-bold shadow-lg shadow-sky-500/20 transition-all cursor-pointer"
            >
              <Camera className="w-4 h-4" />
              <span>Ảnh Chụp HD</span>
            </button>

            <button
              type="button"
              onClick={onCaptureDepthMap}
              className="flex items-center justify-center gap-2 p-3 rounded-2xl bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white text-xs font-bold shadow-lg shadow-indigo-500/20 transition-all cursor-pointer"
            >
              <Layers className="w-4 h-4 text-purple-300" />
              <span>Xuất Depth Map</span>
            </button>
          </div>

          <div className="p-2.5 rounded-xl bg-purple-500/10 border border-purple-500/20 text-[11px] text-purple-200 leading-relaxed">
            💡 <strong>Quy trình A3D × ComfyUI:</strong> Nạp file Depth Map vừa xuất vào node{' '}
            <code className="px-1 py-0.5 rounded bg-black/40 font-mono text-amber-300">
              ControlNet Depth
            </code>{' '}
            để AI tái tạo phối cảnh tòa nhà chuẩn 100% tỷ lệ khối thực tế.
          </div>
        </div>

        {/* ================= 2. BỘ PROMPT AI KIẾN TRÚC ================= */}
        <div className="space-y-2 pt-2 border-t border-white/10">
          <div className="text-[11px] font-bold text-slate-300 font-mono uppercase tracking-wider flex items-center justify-between">
            <span>2. Gợi Ý Prompt AI Kiến Trúc</span>
            <span className="text-[10px] text-slate-500 font-sans">Click để chọn</span>
          </div>

          <div className="space-y-1.5">
            {AI_PROMPT_PRESETS.map((p) => (
              <div
                key={p.id}
                onClick={() => setSelectedPreset(p)}
                className={`p-2.5 rounded-xl border transition-all cursor-pointer ${
                  selectedPreset.id === p.id
                    ? 'bg-sky-500/15 border-sky-500/40 text-white'
                    : 'bg-white/[0.03] border-white/5 text-slate-300 hover:bg-white/[0.07]'
                }`}
              >
                <div className="flex items-center justify-between">
                  <span className="text-xs font-bold">{p.title}</span>
                  <span className="text-[10px] font-mono text-sky-400 font-semibold">{p.tag}</span>
                </div>
                <div className="text-[10px] text-slate-400 mt-1 line-clamp-1">{p.promptVi}</div>
              </div>
            ))}
          </div>

          {/* Chi tiết Prompt đã chọn */}
          <div className="p-3 rounded-2xl bg-slate-950/80 border border-white/10 space-y-2.5">
            <div>
              <div className="flex items-center justify-between mb-1">
                <span className="text-[10px] font-mono text-amber-400 font-bold uppercase tracking-wide">
                  Prompt Tiếng Anh (Model Input)
                </span>
                <button
                  type="button"
                  onClick={() => handleCopyPrompt(selectedPreset)}
                  className="flex items-center gap-1 px-2 py-0.5 rounded-lg bg-sky-500/20 text-sky-300 hover:bg-sky-500/30 text-[10px] font-bold transition-all cursor-pointer"
                >
                  {copiedId === selectedPreset.id ? (
                    <>
                      <Check className="w-3 h-3 text-emerald-400" />
                      <span className="text-emerald-400">Đã chép!</span>
                    </>
                  ) : (
                    <>
                      <Copy className="w-3 h-3" />
                      <span>Sao chép</span>
                    </>
                  )}
                </button>
              </div>
              <p className="text-[11px] text-slate-300 font-mono bg-black/40 p-2.5 rounded-xl border border-white/5 leading-relaxed break-words">
                {selectedPreset.promptEn}
              </p>
            </div>

            <div className="text-[10px] text-slate-400 space-y-1">
              <div>
                <strong className="text-slate-300">Khuyên dùng:</strong>{' '}
                <span className="text-emerald-400 font-mono">{selectedPreset.recommendedModel}</span>
              </div>
              <div>
                <strong className="text-slate-300">Pipeline ComfyUI:</strong>{' '}
                <span className="text-purple-300 font-mono">{selectedPreset.comfyNodes}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};
