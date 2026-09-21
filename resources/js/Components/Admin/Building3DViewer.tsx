import React, { useEffect, useRef, useState } from 'react';
import * as THREE from 'three';
import {
  Building2,
  Sun,
  Moon,
  Sunset,
  Eye,
  Camera,
  RotateCw,
  RefreshCw,
  Layers,
  Sparkles,
  ShieldCheck,
  Zap,
  Users,
  Thermometer,
  Maximize2,
  Minimize2,
  Server,
  MapPin,
  Car,
  Compass,
  Waves,
  TreePine,
  BedDouble,
  ShoppingBag,
  Film,
  Utensils,
  Armchair,
  LayoutGrid,
  Activity,
  Droplets,
  Wind,
  Gauge,
  CheckCircle2,
  Info,
  Wand2,
  FolderTree,
  SlidersHorizontal,
  Grid
} from 'lucide-react';
import {
  A3DShadingMode,
  A3DAspectRatio,
  A3DOutlinerItem,
  A3DEnvironmentSettings
} from './A3DStudio/types';
import { A3DRatioOverlay } from './A3DStudio/A3DRatioOverlay';
import { A3DSceneOutliner } from './A3DStudio/A3DSceneOutliner';
import { A3DEnvironmentPanel } from './A3DStudio/A3DEnvironmentPanel';
import { A3DAiRenderStudio } from './A3DStudio/A3DAiRenderStudio';
import { A3DStudioDock } from './A3DStudio/A3DStudioDock';

import {
  PascalLevelMode,
  PascalMeasurement,
  PascalInspectorData,
} from './PascalStudio/types';
import { PascalLevelControl } from './PascalStudio/PascalLevelControl';
import { PascalBimInspector } from './PascalStudio/PascalBimInspector';
import { PascalAgentConsole } from './PascalStudio/PascalAgentConsole';
import { PascalMeasureOverlay } from './PascalStudio/PascalMeasureOverlay';

export interface BuildingBlockData {
  id: string;
  code: string;
  name: string;
  floors: number;
  basements: number;
  apartments: number;
  residents: number;
  occupancyRate: number;
  powerKw: number;
  temp: number;
  status: 'ACTIVE' | 'MAINTENANCE' | 'INACTIVE';
  color: number;
  position: [number, number, number];
  description?: string;
  category: 'tower' | 'podium' | 'amenity' | 'basement' | 'landscape';
}

export interface SitePin {
  id: number;
  title: string;
  subtitle: string;
  pos: [number, number, number];
  category: string;
}

export interface FloorData {
  level: string;
  name: string;
  elevation: string;
  functions: string[];
  areaM2: number;
  icon: string;
  color: string;
  cameraY: number;
  cameraRadius: number;
}

export const FLOOR_LEVELS: FloorData[] = [
  {
    level: 'B1-B2',
    name: 'Tầng Hầm Bãi Xe & Kỹ Thuật (Sheet 4)',
    elevation: '-5.000m',
    functions: ['Bãi đỗ xe ô tô (450 chỗ)', 'Bãi xe máy (1.200 xe)', 'Phòng PCCC & Trạm biến áp', 'Khu kỹ thuật thông gió hầm'],
    areaM2: 24200,
    icon: 'Car',
    color: '#f59e0b',
    cameraY: -2,
    cameraRadius: 55,
  },
  {
    level: 'Tầng 1',
    name: 'Đại Sảnh & Khu Ẩm Thực Phố (Sheet 2)',
    elevation: '+0.000m',
    functions: ['Sảnh đón khách sạn 5★ cao cấp', 'Sảnh văn phòng riêng biệt', 'Starbucks Coffee & KFC', 'Buffet Tokbokki & Shophouse', 'Quảng trường đài phun nước'],
    areaM2: 8450,
    icon: 'Utensils',
    color: '#0284c7',
    cameraY: 2,
    cameraRadius: 46,
  },
  {
    level: 'Tầng 2',
    name: 'Trung Tâm Mua Sắm & Atrium (Sheet 2)',
    elevation: '+5.000m',
    functions: ['Siêu thị tổng hợp cao cấp', 'Nhà sách & Khu thời trang', 'Khoang thông tầng Atrium mái kính', 'Khu vui chơi giải trí trẻ em'],
    areaM2: 6580,
    icon: 'ShoppingBag',
    color: '#6366f1',
    cameraY: 5,
    cameraRadius: 44,
  },
  {
    level: 'Tầng 3',
    name: 'Spa, Gym, Bar Club & Bể Bơi (Sheet 3)',
    elevation: '+10.000m',
    functions: ['Tổ hợp Bar Club & Lounge', 'Khu Spa & Chăm sóc sức khỏe', 'Phòng Gym & Yoga view biển', 'Khu ẩm thực Á - Âu'],
    areaM2: 5620,
    icon: 'Waves',
    color: '#06b6d4',
    cameraY: 7,
    cameraRadius: 40,
  },
  {
    level: 'Tầng 4-5',
    name: 'Rạp Phim & Bể Bơi Vô Cực Ốc Đảo (Sheet 4)',
    elevation: '+16.000m',
    functions: ['Rạp chiếu phim hiện đại (4 phòng)', 'Bể bơi vô cực ốc đảo dừa', 'Sàn tắm nắng Teak Wood', 'Phòng hội thảo đa năng quốc tế'],
    areaM2: 4800,
    icon: 'Film',
    color: '#10b981',
    cameraY: 9,
    cameraRadius: 38,
  },
  {
    level: 'Tầng 6-28',
    name: 'Khối Phòng Khách Sạn & VIP Suite (Sheet 9 & 10)',
    elevation: '+20.000m -> +95.000m',
    functions: ['336 Căn hộ khách sạn & Condotel', '3 Phòng VIP Suite góc panorama 180°', 'Lõi thang máy tam giác 3 buồng', 'Rooftop Sky Lounge & Mái vát chéo'],
    areaM2: 32500,
    icon: 'BedDouble',
    color: '#38bdf8',
    cameraY: 18,
    cameraRadius: 58,
  },
];

export const SITE_PINS: SitePin[] = [
  { id: 1, title: 'Lối vào chính', subtitle: 'Trục đại lộ phía Đông dẫn vào sảnh danh dự', pos: [26, 0.6, 4], category: 'traffic' },
  { id: 2, title: 'Lối vào hầm', subtitle: 'Ram dốc uốn lượn xuống bãi xe ngầm B1-B2', pos: [20, 0.4, 11], category: 'traffic' },
  { id: 3, title: 'Lối ra chính', subtitle: 'Làn phân luồng một chiều ra nút giao thông', pos: [26, 0.6, -4], category: 'traffic' },
  { id: 4, title: 'Lối thoát hầm', subtitle: 'Ram dốc thoát hiểm & xe ra phía Tây', pos: [-28, 0.4, 0], category: 'traffic' },
  { id: 5, title: 'Sảnh đón khách sạn 5★', subtitle: 'Chi tiết sảnh chính: Cột lam đứng & mái drop-off cong (Sheet 9)', pos: [14, 1.5, 7], category: 'lobby' },
  { id: 6, title: 'Quảng trường trung tâm', subtitle: 'Quảng trường ánh sáng, đài phun nước & công viên', pos: [8, 0.3, 20], category: 'landscape' },
  { id: 7, title: 'Khu nhà hàng ẩm thực', subtitle: 'Nhà hàng sang trọng ven hồ & ẩm thực quốc tế', pos: [0, 1.8, -15], category: 'fnb' },
  { id: 8, title: 'Không gian mặt nước', subtitle: 'Hồ nước cảnh quan uốn lượn ôm trọn khối đế', pos: [-6, 0.3, 15], category: 'landscape' },
  { id: 9, title: 'Bể bơi vô cực & ốc đảo', subtitle: 'Hồ bơi cong hữu cơ tầng 5 & ốc đảo dừa nhiệt đới', pos: [6, 5.2, 5], category: 'amenity' },
  { id: 10, title: 'Tháp Khách Sạn 28T (Tower 10)', subtitle: 'Tháp tam giác bo góc, mái vát chéo, ban công vươn & VIP Suite (Sheet 10)', pos: [6, 18, -2], category: 'tower' },
  { id: 11, title: 'Tháp Văn Phòng 12T (Tower 11)', subtitle: 'Khối văn phòng hạng A kết nối cầu kính & vòm Cassavas', pos: [-14, 8, -2], category: 'tower' },
  { id: 12, title: 'Mái vòm Cassavas & LED Wall', subtitle: 'Vòm cong điêu khắc biểu tượng Cassavas & Màn hình LED kỹ thuật số', pos: [-18, 3.5, 10], category: 'podium' },
  { id: 13, title: 'Cầu kính Skybridge', subtitle: 'Cầu kính trên không tầng 8-10 nối liền 2 khối tháp', pos: [-4, 9, -2], category: 'tower' },
  { id: 14, title: 'Cầu đi bộ cảnh quan gỗ', subtitle: 'Cầu gỗ Teak uốn lượn bắc qua hồ nước sinh thái', pos: [6, 2.2, 18], category: 'landscape' },
];

export const ARCHITECTURAL_BLOCKS: BuildingBlockData[] = [
  {
    id: 'block-hotel-tower',
    code: 'TOWER-10',
    name: 'Tháp Khách Sạn 5★ & Căn Hộ VIP (Mặt Đứng Tây - Đông Sheet 10)',
    floors: 28,
    basements: 2,
    apartments: 336,
    residents: 620,
    occupancyRate: 94.5,
    powerKw: 480,
    temp: 24.5,
    status: 'ACTIVE',
    color: 0x0284c7,
    position: [6, 14, -2],
    description: 'Tháp tam giác khí động học 28 tầng cao 95m. Mặt đứng Tây-Đông đặc trưng với đỉnh mái vát chéo, hàng ban công công-son vươn ra đón gió biển, 3 góc phòng VIP Suite panorama và khe kính trục Nam.',
    category: 'tower',
  },
  {
    id: 'block-office-tower',
    code: 'TOWER-11',
    name: 'Tháp Văn Phòng Cao Cấp (Sheet 10)',
    floors: 12,
    basements: 2,
    apartments: 48,
    residents: 450,
    occupancyRate: 88.0,
    powerKw: 260,
    temp: 23.8,
    status: 'ACTIVE',
    color: 0x38bdf8,
    position: [-14, 6, -2],
    description: 'Khối tháp văn phòng 12 tầng đối thoại hài hòa, dải kính ngang liên tục, kết nối cầu kính tầng 8-10 và khối đế thương mại.',
    category: 'tower',
  },
  {
    id: 'block-podium-mall',
    code: 'PODIUM-MALL',
    name: 'Khối Đế Thương Mại & Vòm Cassavas (Sheet 10 & 9)',
    floors: 4,
    basements: 2,
    apartments: 72,
    residents: 1200,
    occupancyRate: 98.2,
    powerKw: 390,
    temp: 25.0,
    status: 'ACTIVE',
    color: 0x6366f1,
    position: [-2, 2.2, 2],
    description: 'Khối đế uốn cong chữ L ôm quảng trường. Cánh Tây sở hữu mái vòm cong biểu tượng Cassavas kèm màn hình LED kỹ thuật số cỡ lớn và cửa hàng Flagship Adidas.',
    category: 'podium',
  },
  {
    id: 'block-infinity-pool',
    code: 'POOL-TERRACE',
    name: 'Tổ Hợp Bể Bơi Vô Cực & Sky Bar Tầng 5 (Sheet 4)',
    floors: 1,
    basements: 0,
    apartments: 0,
    residents: 180,
    occupancyRate: 75.0,
    powerKw: 120,
    temp: 27.5,
    status: 'ACTIVE',
    color: 0x06b6d4,
    position: [6, 4.8, 5],
    description: 'Bể bơi tràn bờ uốn cong sinh thái với ốc đảo dừa nhiệt đới trung tâm, sàn gỗ tắm nắng Teak Wood và khu Sky Bar ngắm trọn biển Đà Nẵng.',
    category: 'amenity',
  },
  {
    id: 'block-basement-b1b2',
    code: 'BASEMENT-PARK',
    name: 'Bãi Đỗ Xe Ngầm B1-B2 (Sheet 4)',
    floors: 2,
    basements: 2,
    apartments: 0,
    residents: 0,
    occupancyRate: 82.4,
    powerKw: 150,
    temp: 22.0,
    status: 'ACTIVE',
    color: 0xf59e0b,
    position: [-2, -2.5, 4],
    description: 'Hai tầng hầm đỗ xe thông minh theo lưới cột kết cấu 8.4m, trang bị hệ thống sạc xe điện, ram dốc phân luồng một chiều Cổng 2 và Cổng 4.',
    category: 'basement',
  },
  {
    id: 'block-landscape-plaza',
    code: 'CENTRAL-PLAZA',
    name: 'Quảng Trường Ánh Sáng & Hồ Nước (Sheet 1)',
    floors: 1,
    basements: 0,
    apartments: 0,
    residents: 350,
    occupancyRate: 60.0,
    powerKw: 45,
    temp: 26.5,
    status: 'ACTIVE',
    color: 0x10b981,
    position: [8, 0.2, 18],
    description: 'Quảng trường lát đá hoa cương đồng tâm với đài phun nước nghệ thuật, cầu gỗ uốn lượn vượt hồ sinh thái kết nối các phân khu giao thông.',
    category: 'landscape',
  },
];

// Hotspots cho phòng VIP Suite 3D (Sheet 9)
export const VIP_SUITE_HOTSPOTS = [
  { id: 'marble', title: 'Vách Đá Marble Ý & Chỉ Nẹp Vàng PVD', desc: 'Đá cẩm thạch xám vân mây cao cấp kết hợp nẹp kim loại mạ vàng hình học tinh tế (Sheet 9)', pos: [0, 2.2, -4.8] },
  { id: 'chandelier', title: 'Đèn Chùm Thả Trần Ring LED', desc: 'Hệ đèn LED thả trần lồng ghép vòng tròn tham số tạo điểm nhấn trung tâm phòng khách VIP', pos: [0, 3.4, 0] },
  { id: 'sofa', title: 'Sofa Góc Cao Cấp & Đôn Houndstooth', desc: 'Sofa băng chữ L bọc da/nỉ cao cấp đi kèm ghế đôn hoa văn zíc-zắc houndstooth phong cách Italy', pos: [0, 0.8, -0.5] },
  { id: 'puppy', title: 'Ghế Tượng Chú Cún Magis Puppy', desc: 'Tượng decor điêu khắc nghệ thuật chú cún màu trắng bóng đặt cạnh bàn trà', pos: [-1.8, 0.5, 0.5] },
  { id: 'armchair', title: 'Ghế Thư Giãn Da Bò & Đèn Cây Đọc Sách', desc: 'Ghế bành thư giãn da màu nâu cognac, đôn gác chân và đèn cây uốn cong đón ánh sáng biển', pos: [3.2, 1.2, 1.5] },
  { id: 'balcony', title: 'Cửa Kính Lùa & Ban Công Biển Đà Nẵng', desc: 'Hệ vách kính hộp Low-E kịch trần mở rộng tầm nhìn 180° ra bờ biển và đại dương xanh', pos: [0, 1.8, 5.0] },
];

export interface Building3DViewerProps {
  onSelectBlock?: (block: BuildingBlockData) => void;
  className?: string;
}

// 1. Tạo vân kính phản chiếu Curtain Wall
// 1. Texture vách kính Curtain Wall chuẩn kiến trúc PBR (Low-E Double Glazed Unit)
function createCurtainWallTexture(timeMode: 'day' | 'sunset' | 'night'): THREE.CanvasTexture {
  const canvas = document.createElement('canvas');
  canvas.width = 512;
  canvas.height = 512;
  const ctx = canvas.getContext('2d')!;

  // Nền kính Low-E sẫm màu kiến trúc (Dark Neutral Tint, không bị rực xanh nhựa)
  const baseGlassColor =
    timeMode === 'night' ? '#070e17' : timeMode === 'sunset' ? '#141724' : '#111c26';
  ctx.fillStyle = baseGlassColor;
  ctx.fillRect(0, 0, 512, 512);

  const cols = 8;
  const rows = 16;
  const colW = 512 / cols;
  const rowH = 512 / rows;

  // Vẽ các panel kính kiến trúc với dải phản quang và đố nhôm định hình
  for (let r = 0; r < rows; r++) {
    const isFloorSpandrel = r % 4 === 0; // Tấm ốp dầm sàn bê tông (Floor Slab Spandrel)
    const y = r * rowH;

    for (let c = 0; c < cols; c++) {
      const x = c * colW;

      if (isFloorSpandrel) {
        // Dải Spandrel kim loại sơn tĩnh điện xám đen che sàn tầng
        const spandrelGrad = ctx.createLinearGradient(x, y, x, y + rowH);
        spandrelGrad.addColorStop(0, '#1e293b');
        spandrelGrad.addColorStop(0.5, '#0f172a');
        spandrelGrad.addColorStop(1, '#1e293b');
        ctx.fillStyle = spandrelGrad;
        ctx.fillRect(x + 1, y + 1, colW - 2, rowH - 2);

        // Chỉ nhôm bóng nhẹ
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.12)';
        ctx.lineWidth = 1;
        ctx.strokeRect(x + 1, y + 1, colW - 2, rowH - 2);
      } else {
        // Vách kính tầm nhìn (Vision Glass)
        if (timeMode === 'night') {
          // Ban đêm: Phòng có đèn sáng ấm, phòng tắt đèn
          const hasLight = (r * 7 + c * 13) % 5 === 0 || (r * 3 + c * 11) % 4 === 0;
          if (hasLight) {
            const warmGlow = ctx.createLinearGradient(x, y, x, y + rowH);
            warmGlow.addColorStop(0, '#fef08a');
            warmGlow.addColorStop(0.6, '#fde047');
            warmGlow.addColorStop(1, '#ea580c');
            ctx.fillStyle = warmGlow;
            ctx.fillRect(x + 2, y + 2, colW - 4, rowH - 4);

            // Giả lập rèm sáo cuốn / vách ngăn nội thất
            ctx.fillStyle = 'rgba(15, 23, 42, 0.35)';
            ctx.fillRect(x + 2, y + 2, colW - 4, (rowH - 4) * 0.35);
          } else {
            ctx.fillStyle = '#09131d';
            ctx.fillRect(x + 2, y + 2, colW - 4, rowH - 4);
          }
        } else {
          // Ban ngày & Hoàng hôn: Kính Low-E phản xạ bầu trời tự nhiên
          const glassGrad = ctx.createLinearGradient(x, y, x + colW, y + rowH);
          if (timeMode === 'sunset') {
            glassGrad.addColorStop(0, 'rgba(249, 115, 22, 0.25)');
            glassGrad.addColorStop(0.5, 'rgba(30, 27, 75, 0.6)');
            glassGrad.addColorStop(1, 'rgba(15, 23, 42, 0.85)');
          } else {
            glassGrad.addColorStop(0, 'rgba(224, 242, 254, 0.22)');
            glassGrad.addColorStop(0.4, 'rgba(30, 41, 59, 0.65)');
            glassGrad.addColorStop(1, 'rgba(15, 23, 42, 0.90)');
          }
          ctx.fillStyle = glassGrad;
          ctx.fillRect(x + 2, y + 2, colW - 4, rowH - 4);
        }

        // Viền đố nhôm kỹ thuật (Dark Anodized Aluminum Mullion)
        ctx.strokeStyle = '#0b1118';
        ctx.lineWidth = 2;
        ctx.strokeRect(x, y, colW, rowH);

        // Đường gân kim loại highlight mảnh
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.08)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(x + 1, y + 1);
        ctx.lineTo(x + colW - 1, y + 1);
        ctx.stroke();
      }
    }
  }

  const texture = new THREE.CanvasTexture(canvas);
  texture.wrapS = THREE.RepeatWrapping;
  texture.wrapT = THREE.RepeatWrapping;
  texture.repeat.set(4, 8);
  return texture;
}

// 1.1 Tạo môi trường phản xạ khí quyển Procedural PMREM IBL
function createProceduralSkyEnvironment(
  renderer: THREE.WebGLRenderer,
  timeMode: 'day' | 'sunset' | 'night'
): THREE.Texture {
  const canvas = document.createElement('canvas');
  canvas.width = 1024;
  canvas.height = 512;
  const ctx = canvas.getContext('2d')!;

  const grad = ctx.createLinearGradient(0, 0, 0, 512);
  if (timeMode === 'night') {
    grad.addColorStop(0.0, '#020617'); // Đỉnh trời đêm sẫm
    grad.addColorStop(0.45, '#0f172a');
    grad.addColorStop(0.5, '#1e293b');  // Chân trời ánh đèn thành phố
    grad.addColorStop(0.55, '#090d16');
    grad.addColorStop(1.0, '#020408');
  } else if (timeMode === 'sunset') {
    grad.addColorStop(0.0, '#1e1b4b'); // Đỉnh trời tím thẫm
    grad.addColorStop(0.35, '#701a75');
    grad.addColorStop(0.48, '#ea580c'); // Vầng sáng hoàng hôn
    grad.addColorStop(0.5, '#fef08a');  // Đường chân trời vàng rực
    grad.addColorStop(0.54, '#451a03'); // Phản xạ mặt đất
    grad.addColorStop(1.0, '#180d05');
  } else {
    // Bầu trời ban ngày chân thực (Atmospheric Rayleigh Scattering)
    grad.addColorStop(0.0, '#1d4ed8'); // Đỉnh trời xanh hoàng gia
    grad.addColorStop(0.32, '#60a5fa'); // Tầng mây xanh cerulean
    grad.addColorStop(0.48, '#e0f2fe'); // Chân trời trắng mờ ấm
    grad.addColorStop(0.5, '#ffffff');  // Vạch chân trời sáng
    grad.addColorStop(0.53, '#94a3b8'); // Sương mù mặt đất
    grad.addColorStop(0.65, '#475569'); // Phản xạ đô thị
    grad.addColorStop(1.0, '#1e293b');
  }

  ctx.fillStyle = grad;
  ctx.fillRect(0, 0, 1024, 512);

  // Đĩa mặt trời mềm
  if (timeMode !== 'night') {
    const sunX = timeMode === 'sunset' ? 620 : 512;
    const sunY = timeMode === 'sunset' ? 240 : 120;
    const sunGrad = ctx.createRadialGradient(sunX, sunY, 5, sunX, sunY, 180);
    sunGrad.addColorStop(0, timeMode === 'sunset' ? 'rgba(255, 240, 200, 1.0)' : 'rgba(255, 255, 255, 1.0)');
    sunGrad.addColorStop(0.15, timeMode === 'sunset' ? 'rgba(251, 146, 60, 0.7)' : 'rgba(224, 242, 254, 0.5)');
    sunGrad.addColorStop(1, 'rgba(255, 255, 255, 0)');
    ctx.fillStyle = sunGrad;
    ctx.fillRect(0, 0, 1024, 512);
  }

  const texture = new THREE.CanvasTexture(canvas);
  texture.mapping = THREE.EquirectangularReflectionMapping;
  texture.needsUpdate = true;

  const pmremGen = new THREE.PMREMGenerator(renderer);
  pmremGen.compileEquirectangularShader();
  const renderTarget = pmremGen.fromEquirectangular(texture);
  texture.dispose();
  pmremGen.dispose();

  return renderTarget.texture;
}

// 2. Texture đá Marble Ý có vân xám và chỉ kim loại vàng PVD (Sheet 9)
function createMarbleWallTexture(): THREE.CanvasTexture {
  const canvas = document.createElement('canvas');
  canvas.width = 512;
  canvas.height = 512;
  const ctx = canvas.getContext('2d')!;

  const bgGrad = ctx.createLinearGradient(0, 0, 512, 512);
  bgGrad.addColorStop(0, '#64748b');
  bgGrad.addColorStop(0.5, '#475569');
  bgGrad.addColorStop(1, '#334155');
  ctx.fillStyle = bgGrad;
  ctx.fillRect(0, 0, 512, 512);

  ctx.strokeStyle = 'rgba(241, 245, 249, 0.35)';
  ctx.lineWidth = 3;
  ctx.filter = 'blur(4px)';
  for (let i = 0; i < 6; i++) {
    ctx.beginPath();
    ctx.moveTo(Math.random() * 512, 0);
    ctx.bezierCurveTo(
      Math.random() * 512, 150,
      Math.random() * 512, 350,
      Math.random() * 512, 512
    );
    ctx.stroke();
  }
  ctx.filter = 'none';

  ctx.strokeStyle = '#eab308';
  ctx.lineWidth = 4;
  ctx.shadowColor = '#fef08a';
  ctx.shadowBlur = 8;
  
  ctx.beginPath();
  ctx.moveTo(140, 0);
  ctx.lineTo(140, 512);
  ctx.moveTo(370, 0);
  ctx.lineTo(370, 512);
  ctx.moveTo(0, 220);
  ctx.lineTo(512, 220);
  ctx.stroke();

  const texture = new THREE.CanvasTexture(canvas);
  return texture;
}

// 3. Texture vải Houndstooth (Sheet 9)
function createHoundstoothTexture(): THREE.CanvasTexture {
  const canvas = document.createElement('canvas');
  canvas.width = 64;
  canvas.height = 64;
  const ctx = canvas.getContext('2d')!;

  ctx.fillStyle = '#f8fafc';
  ctx.fillRect(0, 0, 64, 64);

  ctx.fillStyle = '#0f172a';
  ctx.fillRect(0, 0, 32, 32);
  ctx.beginPath();
  ctx.moveTo(32, 0);
  ctx.lineTo(64, 32);
  ctx.lineTo(32, 32);
  ctx.fill();

  ctx.beginPath();
  ctx.moveTo(0, 32);
  ctx.lineTo(32, 64);
  ctx.lineTo(0, 64);
  ctx.fill();

  const texture = new THREE.CanvasTexture(canvas);
  texture.wrapS = THREE.RepeatWrapping;
  texture.wrapT = THREE.RepeatWrapping;
  texture.repeat.set(8, 8);
  return texture;
}

// 4. Màn hình quảng cáo số cong Cassavas (Sheet 10)
function createCassavasScreenTexture(): THREE.CanvasTexture {
  const canvas = document.createElement('canvas');
  canvas.width = 512;
  canvas.height = 256;
  const ctx = canvas.getContext('2d')!;

  const grad = ctx.createLinearGradient(0, 0, 512, 256);
  grad.addColorStop(0, '#0284c7');
  grad.addColorStop(0.5, '#4f46e5');
  grad.addColorStop(1, '#06b6d4');
  ctx.fillStyle = grad;
  ctx.fillRect(0, 0, 512, 256);

  ctx.fillStyle = '#ffffff';
  ctx.font = 'bold 44px sans-serif';
  ctx.textAlign = 'center';
  ctx.fillText('CASSAVAS', 256, 110);

  ctx.font = '20px sans-serif';
  ctx.fillStyle = '#bae6fd';
  ctx.fillText('LUXURY SUITE & RESORT RESIDENCES', 256, 150);

  ctx.strokeStyle = '#ffffff';
  ctx.lineWidth = 3;
  ctx.strokeRect(20, 20, 472, 216);

  const texture = new THREE.CanvasTexture(canvas);
  return texture;
}

// 5. Thảm cỏ tự nhiên vi hạt sợi cỏ (Procedural PBR Lawn Texture)
function createRealisticLawnTexture(): THREE.CanvasTexture {
  const canvas = document.createElement('canvas');
  canvas.width = 512;
  canvas.height = 512;
  const ctx = canvas.getContext('2d')!;

  // Nền đất mùn hữu cơ ẩm sẫm màu
  ctx.fillStyle = '#1c2819';
  ctx.fillRect(0, 0, 512, 512);

  // Lớp vi sợi cỏ tự nhiên nhiều tầng màu
  for (let i = 0; i < 28000; i++) {
    const x = Math.random() * 512;
    const y = Math.random() * 512;
    const len = 2.5 + Math.random() * 5.0;
    const angle = (Math.random() - 0.5) * 0.9;
    const gVal = Math.floor(48 + Math.random() * 45);
    const rVal = Math.floor(22 + Math.random() * 26);
    const bVal = Math.floor(18 + Math.random() * 20);
    ctx.strokeStyle = `rgb(${rVal}, ${gVal}, ${bVal})`;
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(x, y);
    ctx.lineTo(x + Math.sin(angle) * len, y - Math.cos(angle) * len);
    ctx.stroke();
  }

  const texture = new THREE.CanvasTexture(canvas);
  texture.wrapS = THREE.RepeatWrapping;
  texture.wrapT = THREE.RepeatWrapping;
  texture.repeat.set(16, 16);
  return texture;
}

// 6. Normal Map gợn sóng mặt nước (Procedural Water Normal Texture)
function createWaterNormalTexture(): THREE.CanvasTexture {
  const canvas = document.createElement('canvas');
  canvas.width = 256;
  canvas.height = 256;
  const ctx = canvas.getContext('2d')!;

  // Nền pháp tuyến phẳng (128, 128, 255)
  ctx.fillStyle = '#8080ff';
  ctx.fillRect(0, 0, 256, 256);

  // Tính toán sóng lăn tăn hình học vi mô
  for (let y = 0; y < 256; y += 4) {
    for (let x = 0; x < 256; x += 4) {
      const w1 = Math.sin(x * 0.12) * Math.cos(y * 0.14);
      const w2 = Math.cos(x * 0.18 + 0.5) * Math.sin(y * 0.1 + 0.8);
      const r = Math.min(255, Math.max(0, Math.floor(128 + (w1 + w2) * 35)));
      const g = Math.min(255, Math.max(0, Math.floor(128 + (w1 - w2) * 35)));
      ctx.fillStyle = `rgb(${r}, ${g}, 255)`;
      ctx.fillRect(x, y, 4, 4);
    }
  }

  const texture = new THREE.CanvasTexture(canvas);
  texture.wrapS = THREE.RepeatWrapping;
  texture.wrapT = THREE.RepeatWrapping;
  texture.repeat.set(8, 8);
  return texture;
}

// 7. Mặt đường Asphalt có vạch sơn kẻ đường (Road Asphalt & Markings)
function createRoadMarkingTexture(isVertical: boolean): THREE.CanvasTexture {
  const canvas = document.createElement('canvas');
  canvas.width = isVertical ? 128 : 512;
  canvas.height = isVertical ? 512 : 128;
  const ctx = canvas.getContext('2d')!;

  // Lớp mặt đường bê tông nhựa Asphalt xám chì nhám
  ctx.fillStyle = '#1e293b';
  ctx.fillRect(0, 0, canvas.width, canvas.height);

  // Hạt đá dăm asphalt li ti
  for (let i = 0; i < 4000; i++) {
    const rx = Math.random() * canvas.width;
    const ry = Math.random() * canvas.height;
    const val = Math.floor(26 + Math.random() * 22);
    ctx.fillStyle = `rgb(${val}, ${val + 2}, ${val + 5})`;
    ctx.fillRect(rx, ry, 2, 2);
  }

  // Vạch sơn tim đường đứt đoạn màu vàng phản quang
  ctx.strokeStyle = '#f59e0b';
  ctx.lineWidth = 3;
  ctx.setLineDash([22, 18]);
  if (isVertical) {
    ctx.beginPath();
    ctx.moveTo(64, 0);
    ctx.lineTo(64, 512);
    ctx.stroke();
  } else {
    ctx.beginPath();
    ctx.moveTo(0, 64);
    ctx.lineTo(512, 64);
    ctx.stroke();
  }

  // Vạch kẻ mép đường màu trắng ngà
  ctx.strokeStyle = '#cbd5e1';
  ctx.lineWidth = 2;
  ctx.setLineDash([]);
  if (isVertical) {
    ctx.beginPath();
    ctx.moveTo(10, 0); ctx.lineTo(10, 512);
    ctx.moveTo(118, 0); ctx.lineTo(118, 512);
    ctx.stroke();
  } else {
    ctx.beginPath();
    ctx.moveTo(0, 10); ctx.lineTo(512, 10);
    ctx.moveTo(0, 118); ctx.lineTo(512, 118);
    ctx.stroke();
  }

  const texture = new THREE.CanvasTexture(canvas);
  texture.wrapS = THREE.RepeatWrapping;
  texture.wrapT = THREE.RepeatWrapping;
  texture.repeat.set(isVertical ? 1 : 4, isVertical ? 4 : 1);
  return texture;
}

// 8. Cây dừa / Cọ nhiệt đới ven biển thực tế (Realistic Coastal Palm Tree)
function createRealisticPalmTree(
  scale: number = 1.0,
  bendAzimuth: number = 0
): THREE.Group {
  const palmGroup = new THREE.Group();

  // Thân dừa uốn cong tự nhiên theo gió biển
  const trunkHeight = 8.2 * scale;
  const curvePts = [
    new THREE.Vector3(0, 0, 0),
    new THREE.Vector3(Math.sin(bendAzimuth) * 0.35 * scale, trunkHeight * 0.25, Math.cos(bendAzimuth) * 0.35 * scale),
    new THREE.Vector3(Math.sin(bendAzimuth) * 1.05 * scale, trunkHeight * 0.55, Math.cos(bendAzimuth) * 1.05 * scale),
    new THREE.Vector3(Math.sin(bendAzimuth) * 1.95 * scale, trunkHeight * 0.82, Math.cos(bendAzimuth) * 1.95 * scale),
    new THREE.Vector3(Math.sin(bendAzimuth) * 2.5 * scale, trunkHeight, Math.cos(bendAzimuth) * 2.5 * scale),
  ];
  const trunkCurve = new THREE.CatmullRomCurve3(curvePts);
  const trunkGeo = new THREE.TubeGeometry(trunkCurve, 20, 0.32 * scale, 8, false);

  const trunkMat = new THREE.MeshStandardMaterial({
    color: 0x483a2e,
    roughness: 0.94,
    metalness: 0.0,
    flatShading: true,
  });
  const trunkMesh = new THREE.Mesh(trunkGeo, trunkMat);
  trunkMesh.castShadow = true;
  trunkMesh.receiveShadow = true;
  palmGroup.add(trunkMesh);

  // Đốt sẹo xơ dừa trên ngọn
  const topPos = curvePts[4];
  const crownJoint = new THREE.Mesh(
    new THREE.CylinderGeometry(0.24 * scale, 0.32 * scale, 0.5 * scale, 8),
    new THREE.MeshStandardMaterial({ color: 0x362b22, roughness: 0.95, metalness: 0.0 })
  );
  crownJoint.position.copy(topPos);
  palmGroup.add(crownJoint);

  // Tán lá dừa (10 - 12 tàu lá rủ xòe cong 3D tự nhiên)
  const frondCount = 11;
  const frondMat = new THREE.MeshStandardMaterial({
    color: 0x22471c,
    roughness: 0.75,
    metalness: 0.0,
    side: THREE.DoubleSide,
    flatShading: true,
  });

  for (let i = 0; i < frondCount; i++) {
    const angle = (i / frondCount) * Math.PI * 2 + (i % 2) * 0.15;
    const frondLen = (4.2 + (i % 3) * 0.35) * scale;
    const droop = (2.4 + (i % 2) * 0.4) * scale;

    const midX = topPos.x + Math.cos(angle) * (frondLen * 0.55);
    const midY = topPos.y + 0.85 * scale;
    const midZ = topPos.z + Math.sin(angle) * (frondLen * 0.55);

    const endX = topPos.x + Math.cos(angle) * frondLen;
    const endY = topPos.y - droop;
    const endZ = topPos.z + Math.sin(angle) * frondLen;

    const frondCurve = new THREE.CatmullRomCurve3([
      topPos,
      new THREE.Vector3(midX, midY, midZ),
      new THREE.Vector3(endX, endY, endZ),
    ]);

    const frondRibbon = new THREE.TubeGeometry(frondCurve, 12, 0.22 * scale, 4, false);
    frondRibbon.scale(1.8, 0.18, 1);
    const frondMesh = new THREE.Mesh(frondRibbon, frondMat);
    frondMesh.castShadow = true;
    palmGroup.add(frondMesh);
  }

  // Buồng dừa nhỏ ở nách lá
  for (let c = 0; c < 3; c++) {
    const nut = new THREE.Mesh(
      new THREE.SphereGeometry(0.2 * scale, 6, 6),
      new THREE.MeshStandardMaterial({ color: 0x3d5022, roughness: 0.85, metalness: 0.0 })
    );
    nut.position.set(
      topPos.x + (Math.random() - 0.5) * 0.35 * scale,
      topPos.y - 0.2 * scale,
      topPos.z + (Math.random() - 0.5) * 0.35 * scale
    );
    palmGroup.add(nut);
  }

  return palmGroup;
}

// 9. Cây bóng mát đô thị cảnh quan thực tế (Realistic Urban Canopy Shade Tree)
function createRealisticCanopyTree(
  scale: number = 1.0,
  seed: number = 0
): THREE.Group {
  const treeGroup = new THREE.Group();

  const trunkH = (3.2 + (seed % 3) * 0.3) * scale;
  const trunkMat = new THREE.MeshStandardMaterial({
    color: 0x34271c,
    roughness: 0.94,
    metalness: 0.0,
    flatShading: true,
  });

  // Gốc và thân chính
  const trunkGeo = new THREE.CylinderGeometry(0.28 * scale, 0.48 * scale, trunkH, 8);
  const trunkMesh = new THREE.Mesh(trunkGeo, trunkMat);
  trunkMesh.position.y = trunkH / 2;
  trunkMesh.castShadow = true;
  trunkMesh.receiveShadow = true;
  treeGroup.add(trunkMesh);

  // Nhánh cành vươn lên
  const branchAngles = [0.3, 2.4, 4.4];
  branchAngles.forEach((ang) => {
    const bGeo = new THREE.CylinderGeometry(0.14 * scale, 0.22 * scale, 2.2 * scale, 6);
    const bMesh = new THREE.Mesh(bGeo, trunkMat);
    bMesh.position.set(
      Math.cos(ang) * 0.4 * scale,
      trunkH - 0.3 * scale,
      Math.sin(ang) * 0.4 * scale
    );
    bMesh.rotation.z = Math.cos(ang) * 0.5;
    bMesh.rotation.x = Math.sin(ang) * 0.5;
    bMesh.castShadow = true;
    treeGroup.add(bMesh);
  });

  // Tán lá đa tầng PBR (Foliage Clumps với flatShading mô phỏng hàng ngàn phiến lá đón sáng)
  const leafColors = [0x1a361a, 0x254b20, 0x345e26, 0x44702e];
  const clumpConfigs = [
    { x: 0, y: trunkH + 1.8 * scale, z: 0, r: 2.2 * scale, col: leafColors[1] },
    { x: 1.2 * scale, y: trunkH + 1.2 * scale, z: 0.6 * scale, r: 1.7 * scale, col: leafColors[0] },
    { x: -1.1 * scale, y: trunkH + 1.4 * scale, z: 0.8 * scale, r: 1.8 * scale, col: leafColors[2] },
    { x: 0.4 * scale, y: trunkH + 1.5 * scale, z: -1.3 * scale, r: 1.6 * scale, col: leafColors[1] },
    { x: -0.8 * scale, y: trunkH + 2.4 * scale, z: -0.5 * scale, r: 1.5 * scale, col: leafColors[3] },
    { x: 0.7 * scale, y: trunkH + 2.5 * scale, z: 0.4 * scale, r: 1.6 * scale, col: leafColors[2] },
    { x: 0, y: trunkH + 3.0 * scale, z: 0, r: 1.4 * scale, col: leafColors[3] },
  ];

  clumpConfigs.forEach((cfg) => {
    const foliageMat = new THREE.MeshStandardMaterial({
      color: cfg.col,
      roughness: 0.88,
      metalness: 0.0,
      flatShading: true,
    });
    const clumpGeo = new THREE.DodecahedronGeometry(cfg.r, 1);
    const clumpMesh = new THREE.Mesh(clumpGeo, foliageMat);
    clumpMesh.position.set(cfg.x, cfg.y, cfg.z);
    clumpMesh.scale.set(1 + (seed % 2) * 0.1, 0.85, 1 + ((seed + 1) % 2) * 0.1);
    clumpMesh.castShadow = true;
    clumpMesh.receiveShadow = true;
    treeGroup.add(clumpMesh);
  });

  return treeGroup;
}

function createRoundedTriangleShape(radius: number, cornerRadius: number = 1.8): THREE.Shape {
  const shape = new THREE.Shape();
  const angles = [
    Math.PI / 2,
    Math.PI / 2 + (2 * Math.PI) / 3,
    Math.PI / 2 + (4 * Math.PI) / 3,
  ];
  const pts = angles.map((a) => new THREE.Vector2(Math.cos(a) * radius, Math.sin(a) * radius));
  const p0 = pts[0], p1 = pts[1], p2 = pts[2];

  const getCornerPoints = (prev: THREE.Vector2, cur: THREE.Vector2, next: THREE.Vector2, dist: number) => {
    const vPrev = new THREE.Vector2().subVectors(prev, cur).normalize().multiplyScalar(dist);
    const vNext = new THREE.Vector2().subVectors(next, cur).normalize().multiplyScalar(dist);
    return {
      start: new THREE.Vector2().addVectors(cur, vPrev),
      end: new THREE.Vector2().addVectors(cur, vNext),
      control: cur,
    };
  };

  const c0 = getCornerPoints(p2, p0, p1, cornerRadius);
  const c1 = getCornerPoints(p0, p1, p2, cornerRadius);
  const c2 = getCornerPoints(p1, p2, p0, cornerRadius);

  shape.moveTo(c0.end.x, c0.end.y);
  shape.lineTo(c1.start.x, c1.start.y);
  shape.quadraticCurveTo(c1.control.x, c1.control.y, c1.end.x, c1.end.y);
  shape.lineTo(c2.start.x, c2.start.y);
  shape.quadraticCurveTo(c2.control.x, c2.control.y, c2.end.x, c2.end.y);
  shape.lineTo(c0.start.x, c0.start.y);
  shape.quadraticCurveTo(c0.control.x, c0.control.y, c0.end.x, c0.end.y);
  shape.closePath();

  return shape;
}

function createPodiumShape(): THREE.Shape {
  const shape = new THREE.Shape();
  shape.moveTo(-22, -10);
  shape.bezierCurveTo(-24, -2, -24, 8, -22, 18);
  shape.bezierCurveTo(-20, 24, -10, 26, 2, 24);
  shape.bezierCurveTo(10, 22, 14, 15, 14, 8);
  shape.bezierCurveTo(14, 3, 18, 1, 20, -3);
  shape.bezierCurveTo(22, -8, 20, -12, 15, -14);
  shape.bezierCurveTo(8, -16, 2, -14, -2, -12);
  shape.bezierCurveTo(-10, -10, -16, -10, -22, -10);
  shape.closePath();
  return shape;
}

function createLakeShape(): THREE.Shape {
  const shape = new THREE.Shape();
  shape.moveTo(-18, 14);
  shape.bezierCurveTo(-16, 24, -6, 28, 4, 26);
  shape.bezierCurveTo(14, 24, 20, 18, 18, 10);
  shape.bezierCurveTo(16, 5, 10, 8, 3, 12);
  shape.bezierCurveTo(-4, 16, -12, 14, -18, 14);
  shape.closePath();
  return shape;
}

function createPoolShape(): THREE.Shape {
  const shape = new THREE.Shape();
  shape.moveTo(4, 0);
  shape.bezierCurveTo(3, 4, 4, 8, 8, 10);
  shape.bezierCurveTo(13, 11, 15, 7, 15, 2);
  shape.bezierCurveTo(15, -2, 10, -3, 6, -1);
  shape.closePath();
  return shape;
}

const INITIAL_OUTLINER_ITEMS: A3DOutlinerItem[] = [
  {
    id: 'hotel',
    name: 'Tháp Khách Sạn 28T (Tower 10)',
    category: 'tower',
    iconName: 'Building2',
    visible: true,
    description: 'Tháp tam giác bo góc 28 tầng cao 95m, mái vát chéo & ban công đón gió biển',
    cameraTarget: { theta: 0.62, phi: 0.48, radius: 52, lookAt: [6, 14, -2] },
  },
  {
    id: 'office',
    name: 'Tháp Văn Phòng 12T (Tower 11)',
    category: 'tower',
    iconName: 'Building2',
    visible: true,
    description: 'Khối tháp văn phòng hạng A 12 tầng, dải kính ngang liên tục',
    cameraTarget: { theta: 1.82, phi: 0.50, radius: 46, lookAt: [-14, 7, -2] },
  },
  {
    id: 'skybridge',
    name: 'Cầu Kính Skybridge Tầng 8-10',
    category: 'tower',
    iconName: 'Building2',
    visible: true,
    description: 'Cầu kính trên không kết nối hai khối tháp chính',
    cameraTarget: { theta: 0.90, phi: 0.60, radius: 36, lookAt: [-4, 8.8, -1] },
  },
  {
    id: 'crown',
    name: 'Đỉnh Mái Vát Slanted Crown',
    category: 'tower',
    iconName: 'Building2',
    visible: true,
    description: 'Đỉnh tháp vát chéo phong cách khí động học',
    cameraTarget: { theta: 0.55, phi: 0.35, radius: 38, lookAt: [6, 26, -2] },
  },
  {
    id: 'podium',
    name: 'Khối Đế Thương Mại (Podium)',
    category: 'podium',
    iconName: 'Layers',
    visible: true,
    description: 'Khối đế uốn lượn chữ L ôm trọn quảng trường ánh sáng',
    cameraTarget: { theta: 0.42, phi: 0.40, radius: 38, lookAt: [4, 5.5, 4] },
  },
  {
    id: 'canopy',
    name: 'Mái Vòm Biểu Tượng Cassavas',
    category: 'podium',
    iconName: 'Layers',
    visible: true,
    description: 'Vòm cong điêu khắc nhận diện thương hiệu Cassavas',
    cameraTarget: { theta: 0.70, phi: 0.45, radius: 32, lookAt: [-16, 4.0, 10] },
  },
  {
    id: 'ledScreen',
    name: 'Màn Hình LED Kỹ Thuật Số Mặt Dựng',
    category: 'podium',
    iconName: 'Layers',
    visible: true,
    description: 'Màn hình LED cong cỡ lớn hiển thị thông điệp',
    cameraTarget: { theta: 0.75, phi: 0.45, radius: 28, lookAt: [-18, 3.2, 10] },
  },
  {
    id: 'lobby',
    name: 'Đại Sảnh Khách Sạn 5★ & Hệ Lam Đứng',
    category: 'podium',
    iconName: 'Layers',
    visible: true,
    description: 'Sảnh đón danh dự sang trọng & hàng cột lam đứng che nắng',
    cameraTarget: { theta: 0.25, phi: 0.48, radius: 32, lookAt: [14, 2.0, 7] },
  },
  {
    id: 'pool',
    name: 'Bể Bơi Vô Cực & Ốc Đảo Dừa Tầng 5',
    category: 'amenity',
    iconName: 'Waves',
    visible: true,
    description: 'Hồ bơi tràn bờ uốn cong, ốc đảo nhiệt đới & sàn tắm nắng Teak Wood',
    cameraTarget: { theta: 0.45, phi: 0.32, radius: 28, lookAt: [2, 5.2, 4] },
  },
  {
    id: 'basement',
    name: 'Bãi Xe Ngầm B1-B2 & Trạm Sạc EV',
    category: 'basement',
    iconName: 'Car',
    visible: true,
    description: '2 tầng hầm đỗ xe thông minh, trạm sạc nhanh xe điện & ram dốc Cổng 2-4',
    cameraTarget: { theta: 0.82, phi: 0.28, radius: 56, lookAt: [-2, -2, 4] },
  },
  {
    id: 'plaza',
    name: 'Quảng Trường Trung Tâm & Cầu Gỗ',
    category: 'landscape',
    iconName: 'TreePine',
    visible: true,
    description: 'Quảng trường đá hoa cương, đài phun nước & cầu gỗ vượt hồ',
    cameraTarget: { theta: 0.95, phi: 0.50, radius: 48, lookAt: [8, 0.5, 20] },
  },
  {
    id: 'lake',
    name: 'Hồ Nước Cảnh Quan Sinh Thái',
    category: 'landscape',
    iconName: 'TreePine',
    visible: true,
    description: 'Hồ nước uốn lượn điều hòa vi khí hậu dự án',
    cameraTarget: { theta: 0.85, phi: 0.45, radius: 52, lookAt: [0, 0.2, 0] },
  },
  {
    id: 'roads',
    name: 'Đại Lộ Phía Đông & Làn Giao Thông',
    category: 'landscape',
    iconName: 'TreePine',
    visible: true,
    description: 'Trục đường chính và đường nội bộ phân luồng một chiều',
    cameraTarget: { theta: 0.90, phi: 0.58, radius: 75, lookAt: [20, 0.5, 20] },
  },
  {
    id: 'beach',
    name: 'Bãi Cát & Đại Dương Biển Đà Nẵng',
    category: 'landscape',
    iconName: 'TreePine',
    visible: true,
    description: 'Bờ cát vàng & mặt nước biển Đông sóng sánh',
    cameraTarget: { theta: -0.85, phi: 0.55, radius: 95, lookAt: [100, 0.5, 0] },
  },
  {
    id: 'city',
    name: 'Bối Cảnh Đô Thị Xung Quanh',
    category: 'landscape',
    iconName: 'TreePine',
    visible: true,
    description: 'Các dãy phố và tòa nhà lân cận tạo bối cảnh đô thị thực tế',
  },
  {
    id: 'worldGrid',
    name: 'Lưới Tọa Độ Thế Giới (World Grid)',
    category: 'lighting',
    iconName: 'Sun',
    visible: true,
    description: 'Lưới đo đạc mặt đất chuẩn Three.js Studio',
  },
  {
    id: 'sunLight',
    name: 'Nguồn Sáng Mặt Trời (Sun Light)',
    category: 'lighting',
    iconName: 'Sun',
    visible: true,
    description: 'Nguồn sáng mặt trời định hướng góc chiếu và bóng đổ',
  },
];

export const Building3DViewer: React.FC<Building3DViewerProps> = ({
  onSelectBlock,
  className = '',
}) => {
  const containerRef = useRef<HTMLDivElement | null>(null);
  const canvasRef = useRef<HTMLCanvasElement | null>(null);

  // UI States
  const [selectedBlock, setSelectedBlock] = useState<BuildingBlockData>(ARCHITECTURAL_BLOCKS[0]);
  const [selectedPin, setSelectedPin] = useState<SitePin | null>(SITE_PINS[9]);
  const [selectedFloor, setSelectedFloor] = useState<FloorData>(FLOOR_LEVELS[5]);
  const [activeTab, setActiveTab] = useState<'overview' | 'floors' | 'vip_suite' | 'typical_plate'>('overview');
  const [activeInspectorTab, setActiveInspectorTab] = useState<'space' | 'mep' | 'materials'>('space');
  const [selectedHotspot, setSelectedHotspot] = useState<typeof VIP_SUITE_HOTSPOTS[0] | null>(VIP_SUITE_HOTSPOTS[0]);
  const [timeMode, setTimeMode] = useState<'day' | 'sunset' | 'night'>('day');
  const [isXRayMode, setIsXRayMode] = useState<boolean>(false);
  const [isBasementView, setIsBasementView] = useState<boolean>(false);
  const [showUrbanContext, setShowUrbanContext] = useState<boolean>(true);
  const [showBeach, setShowBeach] = useState<boolean>(true);
  const [showPins, setShowPins] = useState<boolean>(false);
  const [isAutoRotate, setIsAutoRotate] = useState<boolean>(false);
  const [isFullscreen, setIsFullscreen] = useState<boolean>(false);
  const [viewMode, setViewMode] = useState<'interactive' | 'server_snapshot'>('interactive');
  const [serverSnapshotUrl, setServerSnapshotUrl] = useState<string | null>(null);
  const [isRenderingServer, setIsRenderingServer] = useState<boolean>(false);
  const [serverRenderMsg, setServerRenderMsg] = useState<string | null>(null);

  // A3D Studio States
  const [shadingMode, setShadingMode] = useState<A3DShadingMode>('realistic');
  const [aspectRatio, setAspectRatio] = useState<A3DAspectRatio>('free');
  const [activeA3DDrawer, setActiveA3DDrawer] = useState<'none' | 'outliner' | 'environment' | 'ai_studio' | 'level_control'>('none');
  const [envSettings, setEnvSettings] = useState<A3DEnvironmentSettings>({
    sunElevation: 58,
    sunAzimuth: 45,
    ambientIntensity: 1.2,
    sunIntensity: 2.8,
    shadowQuality: 'soft',
    showWorldGrid: true,
    fogDensity: 0.0065,
  });
  const [outlinerItems, setOutlinerItems] = useState<A3DOutlinerItem[]>(INITIAL_OUTLINER_ITEMS);
  const [containerDims, setContainerDims] = useState<{ width: number; height: number }>({ width: 800, height: 600 });

  // Pascal Studio States
  const [pascalLevelMode, setPascalLevelMode] = useState<PascalLevelMode>('stacked');
  const [pascalExplodeFactor, setPascalExplodeFactor] = useState<number>(1.6);
  const [pascalSoloLevel, setPascalSoloLevel] = useState<string | null>(null);
  const [inspectorData, setInspectorData] = useState<PascalInspectorData | null>(null);
  const [isMeasuring, setIsMeasuring] = useState<boolean>(false);
  const [measureStep, setMeasureStep] = useState<number>(0);
  const [currentMeasurement, setCurrentMeasurement] = useState<PascalMeasurement | null>(null);
  const measureStartPointRef = useRef<[number, number, number] | null>(null);
  const [isAgentConsoleOpen, setIsAgentConsoleOpen] = useState<boolean>(false);

  // References for Three.js
  const sceneRef = useRef<THREE.Scene | null>(null);
  const rendererRef = useRef<THREE.WebGLRenderer | null>(null);
  const cameraRef = useRef<THREE.PerspectiveCamera | null>(null);
  const interactiveMeshesRef = useRef<Map<string, THREE.Object3D>>(new Map());
  const pinObjectsRef = useRef<Map<number, THREE.Object3D>>(new Map());
  const floorHighlightRingRef = useRef<THREE.Mesh | null>(null);
  const oceanMeshRef = useRef<THREE.Mesh | null>(null);

  // A3D Three.js Studio Refs
  const gridHelperRef = useRef<THREE.GridHelper | null>(null);
  const sunLightRef = useRef<THREE.DirectionalLight | null>(null);
  const ambientLightRef = useRef<THREE.AmbientLight | null>(null);
  const clayMatRef = useRef<THREE.MeshStandardMaterial | null>(null);
  const wireframeMatRef = useRef<THREE.MeshBasicMaterial | null>(null);
  const depthMatRef = useRef<THREE.MeshDepthMaterial | null>(null);
  const animReqRef = useRef<number | null>(null);

  // Groups for scene switching
  const exteriorGroupRef = useRef<THREE.Group | null>(null);
  const vipInteriorGroupRef = useRef<THREE.Group | null>(null);
  const typicalPlateGroupRef = useRef<THREE.Group | null>(null);

  // Orbit controls
  const isDraggingRef = useRef<boolean>(false);
  const prevMouseRef = useRef<{ x: number; y: number }>({ x: 0, y: 0 });
  const orbitAngleRef = useRef<{ theta: number; phi: number; radius: number }>({
    theta: 0.90,
    phi: 0.58,
    radius: 78,
  });
  const targetLookAtRef = useRef<THREE.Vector3>(new THREE.Vector3(0, 6, 4));

  // Tải snapshot có sẵn
  useEffect(() => {
    fetch('/api/v1/zones/3d-snapshot')
      .then((res) => res.json())
      .then((data) => {
        if (data.success && data.data?.exists) {
          setServerSnapshotUrl(data.data.url);
        }
      })
      .catch(() => {});
  }, []);

  const handleTriggerServerRender = async () => {
    setIsRenderingServer(true);
    setServerRenderMsg('Đang gửi lệnh kết xuất Three.js tới Node.js WebGL (ANGLE Engine)...');
    try {
      const res = await fetch('/api/v1/zones/render-3d-snapshot', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
      });
      const data = await res.json();
      if (data.success && data.data?.url) {
        setServerSnapshotUrl(data.data.url);
        setServerRenderMsg('Kết xuất Headless WebGL thành công! Đã cập nhật ảnh mới nhất.');
        setTimeout(() => setServerRenderMsg(null), 4000);
      } else {
        setServerRenderMsg(data.message || 'Lỗi kết xuất từ máy chủ.');
      }
    } catch {
      setServerRenderMsg('Lỗi kết nối máy chủ khi chạy node-webgl.');
    } finally {
      setIsRenderingServer(false);
    }
  };

  // Cập nhật vị trí camera từ góc quay & tâm nhìn
  const updateCameraPos = () => {
    if (!cameraRef.current) return;
    const { theta, phi, radius } = orbitAngleRef.current;
    const target = targetLookAtRef.current;
    cameraRef.current.position.x = target.x + radius * Math.sin(phi) * Math.sin(theta);
    cameraRef.current.position.y = target.y + radius * Math.cos(phi);
    cameraRef.current.position.z = target.z + radius * Math.sin(phi) * Math.cos(theta);
    cameraRef.current.lookAt(target);
  };

  // Hàm chuyển động Camera mượt mà (A3D Easing Interpolation)
  const animateCameraTo = (
    targetAngle: { theta: number; phi: number; radius: number },
    targetLookAt: THREE.Vector3,
    duration: number = 650
  ) => {
    if (animReqRef.current) cancelAnimationFrame(animReqRef.current);
    const startAngle = { ...orbitAngleRef.current };
    const startLookAt = targetLookAtRef.current.clone();
    const startTime = performance.now();

    const step = (now: number) => {
      const elapsed = now - startTime;
      const progress = Math.min(elapsed / duration, 1);
      // Easing curve: easeInOutCubic
      const ease =
        progress < 0.5 ? 4 * progress * progress * progress : 1 - Math.pow(-2 * progress + 2, 3) / 2;

      orbitAngleRef.current = {
        theta: startAngle.theta + (targetAngle.theta - startAngle.theta) * ease,
        phi: startAngle.phi + (targetAngle.phi - startAngle.phi) * ease,
        radius: startAngle.radius + (targetAngle.radius - startAngle.radius) * ease,
      };

      targetLookAtRef.current.lerpVectors(startLookAt, targetLookAt, ease);
      updateCameraPos();

      if (progress < 1) {
        animReqRef.current = requestAnimationFrame(step);
      } else {
        animReqRef.current = null;
      }
    };
    animReqRef.current = requestAnimationFrame(step);
  };

  // Preset chuyển đổi camera chuẩn kiến trúc với chuyển động mượt
  const setCameraPreset = (
    mode: 'master' | 'elev_south' | 'elev_west' | 'hotel' | 'office' | 'podium' | 'basement' | 'beach' | 'vip' | 'plate'
  ) => {
    switch (mode) {
      case 'master':
        animateCameraTo({ theta: 0.90, phi: 0.60, radius: 82 }, new THREE.Vector3(0, 7, 4), 650);
        setIsBasementView(false);
        break;
      case 'elev_south':
        // Mặt đứng Nam - Bắc TL 1/150 (Sheet 5)
        animateCameraTo({ theta: 0.0, phi: 1.50, radius: 76 }, new THREE.Vector3(0, 14, 0), 650);
        setIsBasementView(false);
        break;
      case 'elev_west':
        // Mặt đứng Tây - Đông TL 1/150 (Sheet 10)
        animateCameraTo({ theta: 1.57, phi: 1.50, radius: 82 }, new THREE.Vector3(0, 14, 0), 650);
        setIsBasementView(false);
        break;
      case 'hotel':
        animateCameraTo({ theta: 0.62, phi: 0.48, radius: 52 }, new THREE.Vector3(6, 14, -2), 650);
        setIsBasementView(false);
        setSelectedBlock(ARCHITECTURAL_BLOCKS[0]);
        break;
      case 'office':
        animateCameraTo({ theta: 1.82, phi: 0.50, radius: 46 }, new THREE.Vector3(-14, 7, -2), 650);
        setIsBasementView(false);
        setSelectedBlock(ARCHITECTURAL_BLOCKS[1]);
        break;
      case 'podium':
        animateCameraTo({ theta: 0.42, phi: 0.40, radius: 38 }, new THREE.Vector3(4, 5.5, 4), 650);
        setIsBasementView(false);
        setSelectedBlock(ARCHITECTURAL_BLOCKS[3]);
        break;
      case 'basement':
        animateCameraTo({ theta: 0.82, phi: 0.28, radius: 56 }, new THREE.Vector3(-2, -2, 4), 650);
        setIsBasementView(true);
        setSelectedBlock(ARCHITECTURAL_BLOCKS[4]);
        break;
      case 'beach':
        animateCameraTo({ theta: -0.85, phi: 0.55, radius: 95 }, new THREE.Vector3(10, 8, 10), 650);
        setIsBasementView(false);
        break;
      case 'vip':
        animateCameraTo({ theta: 0.35, phi: 0.22, radius: 9 }, new THREE.Vector3(0, 1.6, 0), 650);
        break;
      case 'plate':
        animateCameraTo({ theta: 0.1, phi: 0.82, radius: 28 }, new THREE.Vector3(0, 0, 0), 650);
        break;
    }
  };

  // Pascal Agent Console Command Handler
  const handleExecutePascalCommand = (rawCmd: string): { success: boolean; message: string } => {
    const parts = rawCmd.trim().split(/\s+/);
    const action = parts[0]?.toLowerCase() || '';
    const arg1 = parts[1];

    if (action === '/explode') {
      const factor = arg1 ? parseFloat(arg1) : 1.6;
      if (isNaN(factor) || factor < 0.5 || factor > 3.0) {
        return { success: false, message: 'Tham số không hợp lệ. Sử dụng: /explode [0.5 - 3.0]' };
      }
      setPascalLevelMode('exploded');
      setPascalExplodeFactor(factor);
      return { success: true, message: `Pascal Exploded View kích hoạt (độ giãn Y: ${factor}x)` };
    }

    if (action === '/stacked') {
      setPascalLevelMode('stacked');
      setPascalSoloLevel(null);
      return { success: true, message: 'Đã đưa các tầng về dạng nguyên khối (Stacked Mode)' };
    }

    if (action === '/solo') {
      const levelQuery = parts.slice(1).join(' ').trim();
      if (!levelQuery) {
        return { success: false, message: 'Cú pháp: /solo [B1-B2 | Tầng 1 | Tầng 2 | Tầng 3 | Tầng 4-5 | Tầng 6-28]' };
      }
      const match = FLOOR_LEVELS.find(
        (fl) => fl.level.toLowerCase().includes(levelQuery.toLowerCase()) || fl.name.toLowerCase().includes(levelQuery.toLowerCase())
      );
      if (match) {
        setPascalLevelMode('solo');
        setPascalSoloLevel(match.level);
        handleSelectFloor(match);
        return { success: true, message: `Cách ly tầng: ${match.level} (${match.name})` };
      }
      return { success: false, message: `Không tìm thấy tầng phù hợp với "${levelQuery}"` };
    }

    if (action === '/measure') {
      setIsMeasuring((prev) => !prev);
      setMeasureStep(0);
      setCurrentMeasurement(null);
      measureStartPointRef.current = null;
      return { success: true, message: `Đã ${!isMeasuring ? 'bật' : 'tắt'} thước đo kiến trúc 3D` };
    }

    if (action === '/shading') {
      if (['realistic', 'clay', 'wireframe', 'depth'].includes(arg1)) {
        setShadingMode(arg1 as A3DShadingMode);
        return { success: true, message: `Đã chuyển Shading Mode sang "${arg1.toUpperCase()}"` };
      }
      return { success: false, message: 'Cú pháp: /shading [realistic | clay | wireframe | depth]' };
    }

    if (action === '/focus') {
      if (arg1 === 'hotel' || arg1 === 'tower') {
        setCameraPreset('hotel');
        return { success: true, message: 'Focus camera: Tháp Khách Sạn 28T' };
      }
      if (arg1 === 'office') {
        setCameraPreset('office');
        return { success: true, message: 'Focus camera: Tháp Văn Phòng 12T' };
      }
      if (arg1 === 'podium' || arg1 === 'mall') {
        setCameraPreset('podium');
        return { success: true, message: 'Focus camera: Khối Đế Thương Mại' };
      }
      if (arg1 === 'basement') {
        setCameraPreset('basement');
        return { success: true, message: 'Focus camera: Tầng Hầm B1-B2' };
      }
      return { success: false, message: 'Cú pháp: /focus [hotel | office | podium | basement]' };
    }

    if (action === '/sun') {
      const el = parseFloat(arg1);
      if (!isNaN(el) && el >= 5 && el <= 85) {
        setEnvSettings((s) => ({ ...s, sunElevation: el }));
        return { success: true, message: `Góc mặt trời cập nhật: ${el}°` };
      }
      return { success: false, message: 'Cú pháp: /sun [5 - 85]' };
    }

    if (action === '/grid') {
      const on = arg1 === 'on' || arg1 === 'true';
      setEnvSettings((s) => ({ ...s, showWorldGrid: on }));
      return { success: true, message: `Lưới tọa độ thế giới: ${on ? 'BẬT' : 'TẮT'}` };
    }

    return { success: false, message: `Lệnh không hợp lệ: "${rawCmd}". Gõ lệnh /explode, /solo, /stacked, /measure, /focus, /shading...` };
  };

  // Toggle ẩn hiện đối tượng từ Scene Outliner
  const handleToggleOutlinerItem = (id: string) => {
    setOutlinerItems((prev) =>
      prev.map((it) => {
        if (it.id === id) {
          const nextVisible = !it.visible;
          if (id === 'worldGrid' && gridHelperRef.current) {
            gridHelperRef.current.visible = nextVisible;
            setEnvSettings((s) => ({ ...s, showWorldGrid: nextVisible }));
          } else if (id === 'sunLight' && sunLightRef.current) {
            sunLightRef.current.visible = nextVisible;
          } else {
            const obj = interactiveMeshesRef.current.get(id);
            if (obj) obj.visible = nextVisible;
          }
          return { ...it, visible: nextVisible };
        }
        return it;
      })
    );
  };

  // Focus camera vào đối tượng từ Scene Outliner (A3D Bounding Box Focus)
  const handleFocusOutlinerItem = (item: A3DOutlinerItem) => {
    if (item.cameraTarget) {
      animateCameraTo(
        { theta: item.cameraTarget.theta, phi: item.cameraTarget.phi, radius: item.cameraTarget.radius },
        new THREE.Vector3(...item.cameraTarget.lookAt),
        650
      );
    } else {
      const obj = interactiveMeshesRef.current.get(item.id);
      if (obj) {
        const box = new THREE.Box3().setFromObject(obj);
        const center = new THREE.Vector3();
        box.getCenter(center);
        const size = new THREE.Vector3();
        box.getSize(size);
        const maxDim = Math.max(size.x, size.y, size.z);
        const radius = Math.max(16, maxDim * 2.2);
        animateCameraTo({ theta: orbitAngleRef.current.theta, phi: 0.52, radius }, center, 650);
      }
    }
  };

  // Kết xuất và tải Depth Map (A3D Z-Buffer Pipeline)
  const handleCaptureDepthMap = () => {
    if (!canvasRef.current || !rendererRef.current || !sceneRef.current || !cameraRef.current) return;
    const scene = sceneRef.current;
    const renderer = rendererRef.current;
    const camera = cameraRef.current;

    const prevOverride = scene.overrideMaterial;
    const prevBg = scene.background;

    // Chuyển sang Depth material
    scene.overrideMaterial = new THREE.MeshDepthMaterial();
    scene.background = new THREE.Color(0x000000);
    renderer.render(scene, camera);

    const depthDataUrl = canvasRef.current.toDataURL('image/png');
    const a = document.createElement('a');
    a.href = depthDataUrl;
    a.download = `cassavas_ai_depth_map_${Date.now()}.png`;
    a.click();

    // Khôi phục lại trạng thái cũ
    scene.overrideMaterial = prevOverride;
    scene.background = prevBg;
    renderer.render(scene, camera);
  };

  useEffect(() => {
    if (exteriorGroupRef.current) {
      exteriorGroupRef.current.visible = (activeTab === 'overview' || activeTab === 'floors');
    }
    if (vipInteriorGroupRef.current) {
      vipInteriorGroupRef.current.visible = (activeTab === 'vip_suite');
    }
    if (typicalPlateGroupRef.current) {
      typicalPlateGroupRef.current.visible = (activeTab === 'typical_plate');
    }

    if (activeTab === 'vip_suite') {
      setCameraPreset('vip');
    } else if (activeTab === 'typical_plate') {
      setCameraPreset('plate');
    } else if (activeTab === 'overview') {
      setCameraPreset('master');
    }
  }, [activeTab]);

  const handleSelectFloor = (floor: FloorData) => {
    setSelectedFloor(floor);
    if (floor.level.startsWith('B1')) {
      setIsBasementView(true);
    } else {
      setIsBasementView(false);
    }
    targetLookAtRef.current.set(2, floor.cameraY, 2);
    orbitAngleRef.current.radius = floor.cameraRadius;

    if (floorHighlightRingRef.current) {
      floorHighlightRingRef.current.position.y = Math.max(0.1, floor.cameraY);
      floorHighlightRingRef.current.visible = true;
    }
  };

  useEffect(() => {
    if (!containerRef.current || !canvasRef.current) return;

    const container = containerRef.current;
    const canvas = canvasRef.current;
    const width = container.clientWidth || 800;
    const height = container.clientHeight || 540;

    const scene = new THREE.Scene();
    sceneRef.current = scene;

    const skyColor =
      timeMode === 'night'
        ? 0x050a14
        : timeMode === 'sunset'
        ? 0x2e1026
        : 0xe0f2fe;

    const fogColor =
      timeMode === 'night'
        ? 0x070d1a
        : timeMode === 'sunset'
        ? 0x3b1d32
        : 0xe2e8f0;

    scene.background = new THREE.Color(skyColor);
    scene.fog = new THREE.FogExp2(fogColor, 0.0065);

    const aspect = width / height;
    const camera = new THREE.PerspectiveCamera(42, aspect, 0.2, 1000);
    cameraRef.current = camera;

    const updateCameraPos = () => {
      const { theta, phi, radius } = orbitAngleRef.current;
      const target = targetLookAtRef.current;
      camera.position.x = target.x + radius * Math.sin(phi) * Math.sin(theta);
      camera.position.y = target.y + radius * Math.cos(phi);
      camera.position.z = target.z + radius * Math.sin(phi) * Math.cos(theta);
      camera.lookAt(target);
    };
    updateCameraPos();

    const renderer = new THREE.WebGLRenderer({
      canvas,
      antialias: true,
      powerPreference: 'high-performance',
      preserveDrawingBuffer: true,
    });
    rendererRef.current = renderer;
    renderer.setSize(width, height, false);
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = timeMode === 'night' ? 1.25 : timeMode === 'sunset' ? 1.18 : 1.08;

    // PMREM Procedural Environment Map (Kích hoạt IBL Specular Reflection cho kính và kim loại)
    const envMapTexture = createProceduralSkyEnvironment(renderer, timeMode);
    scene.environment = envMapTexture;

    // Ánh sáng vòm trời khí quyển (Atmospheric Rayleigh Scattering)
    const hemiLight = new THREE.HemisphereLight(
      timeMode === 'night' ? 0x1e293b : timeMode === 'sunset' ? 0xfde047 : 0xe0f2fe,
      timeMode === 'night' ? 0x090d16 : timeMode === 'sunset' ? 0x451a03 : 0x334155,
      envSettings.ambientIntensity * 0.7
    );
    scene.add(hemiLight);

    const ambientLight = new THREE.AmbientLight(
      timeMode === 'night' ? 0x0f172a : timeMode === 'sunset' ? 0x4a044e : 0xffffff,
      envSettings.ambientIntensity * 0.4
    );
    scene.add(ambientLight);
    ambientLightRef.current = ambientLight;

    const sunColor =
      timeMode === 'night' ? 0x93c5fd : timeMode === 'sunset' ? 0xf59e0b : 0xfffbf0;
    const sunLight = new THREE.DirectionalLight(
      sunColor,
      envSettings.sunIntensity
    );
    const radElev = THREE.MathUtils.degToRad(envSettings.sunElevation);
    const radAzim = THREE.MathUtils.degToRad(envSettings.sunAzimuth);
    const sunDist = 90;
    sunLight.position.set(
      sunDist * Math.cos(radElev) * Math.sin(radAzim),
      sunDist * Math.sin(radElev),
      sunDist * Math.cos(radElev) * Math.cos(radAzim)
    );
    sunLight.castShadow = true;
    sunLight.shadow.mapSize.width = 2048;
    sunLight.shadow.mapSize.height = 2048;
    const d = 60;
    sunLight.shadow.camera.left = -d;
    sunLight.shadow.camera.right = d;
    sunLight.shadow.camera.top = d;
    sunLight.shadow.camera.bottom = -d;
    sunLight.shadow.bias = -0.00015;
    sunLight.shadow.normalBias = 0.02;
    scene.add(sunLight);
    sunLightRef.current = sunLight;
    interactiveMeshesRef.current.set('sunLight', sunLight);

    const rimLight = new THREE.DirectionalLight(
      timeMode === 'night' ? 0x1e3a8a : timeMode === 'sunset' ? 0x9333ea : 0xbae6fd,
      timeMode === 'night' ? 1.2 : 0.6
    );
    rimLight.position.set(-40, 35, -30);
    scene.add(rimLight);

    // =========================================================================
    // A3D STUDIO SHADING MATERIALS & WORLD GRID
    // =========================================================================
    const clayMat = new THREE.MeshStandardMaterial({
      color: 0xf1f5f9,
      roughness: 0.88,
      metalness: 0.05,
    });
    clayMatRef.current = clayMat;

    const wireframeMat = new THREE.MeshBasicMaterial({
      color: 0x0284c7,
      wireframe: true,
    });
    wireframeMatRef.current = wireframeMat;

    const depthMat = new THREE.MeshDepthMaterial();
    depthMatRef.current = depthMat;

    // A3D World Grid Helper
    const gridHelper = new THREE.GridHelper(180, 90, 0x0284c7, 0x334155);
    gridHelper.position.y = 0.015;
    if (gridHelper.material instanceof THREE.Material) {
      gridHelper.material.transparent = true;
      gridHelper.material.opacity = 0.4;
    }
    gridHelper.visible = envSettings.showWorldGrid;
    scene.add(gridHelper);
    gridHelperRef.current = gridHelper;
    interactiveMeshesRef.current.set('worldGrid', gridHelper);

    // Cập nhật kích thước container ban đầu
    setContainerDims({ width, height });

    // =========================================================================
    // GROUP A: NGOẠI THẤT DỰ ÁN (EXTERIOR)
    // =========================================================================
    const exteriorGroup = new THREE.Group();
    scene.add(exteriorGroup);
    exteriorGroupRef.current = exteriorGroup;

    const upperGroup = new THREE.Group();
    exteriorGroup.add(upperGroup);

    const basementGroup = new THREE.Group();
    exteriorGroup.add(basementGroup);
    interactiveMeshesRef.current.set('basement', basementGroup);

    const curtainTexture = createCurtainWallTexture(timeMode);

    // Bãi biển & Đại dương Đà Nẵng
    if (showBeach) {
      const beachGroup = new THREE.Group();
      upperGroup.add(beachGroup);

      const sandGeo = new THREE.PlaneGeometry(120, 180);
      const sandMat = new THREE.MeshStandardMaterial({
        color: timeMode === 'night' ? 0x1e293b : timeMode === 'sunset' ? 0xd4a373 : 0xe2d9c8,
        roughness: 0.95,
        metalness: 0.0,
      });
      const sandMesh = new THREE.Mesh(sandGeo, sandMat);
      sandMesh.rotation.x = -Math.PI / 2;
      sandMesh.position.set(100, 0.01, 0);
      sandMesh.receiveShadow = true;
      beachGroup.add(sandMesh);

      const oceanGeo = new THREE.PlaneGeometry(160, 180);
      const waterNormal = createWaterNormalTexture();
      // PBR dielectric water: IOR ~ 1.33, roughness ~ 0.04, normalMap gợn sóng biển, IBL reflection
      const oceanMat = new THREE.MeshStandardMaterial({
        color: timeMode === 'night' ? 0x05131f : timeMode === 'sunset' ? 0x0f2b46 : 0x0b3d59,
        roughness: 0.04,
        metalness: 0.02,
        normalMap: waterNormal,
        normalScale: new THREE.Vector2(0.4, 0.4),
        transparent: true,
        opacity: 0.92,
        envMapIntensity: 2.2,
      });
      const oceanMesh = new THREE.Mesh(oceanGeo, oceanMat);
      oceanMesh.rotation.x = -Math.PI / 2;
      oceanMesh.position.set(220, 0.02, 0);
      beachGroup.add(oceanMesh);
      oceanMeshRef.current = oceanMesh;
      interactiveMeshesRef.current.set('beach', beachGroup);

      // Dải bọt sóng trắng dạt bờ cát (Shoreline Surf Foam)
      const surfMesh = new THREE.Mesh(
        new THREE.PlaneGeometry(8, 180),
        new THREE.MeshStandardMaterial({
          color: 0xf8fafc,
          roughness: 0.92,
          transparent: true,
          opacity: 0.72,
        })
      );
      surfMesh.rotation.x = -Math.PI / 2;
      surfMesh.position.set(140, 0.025, 0);
      beachGroup.add(surfMesh);

      // HÀNG DỪA VEN BIỂN ĐÀ NẴNG (Realistic Coastal Palms)
      const palmCoords: [number, number, number, number][] = [
        [88, -65, 0.95, -0.4], [94, -50, 1.1, -0.2], [86, -35, 1.05, -0.5],
        [92, -20, 0.9, -0.3], [96, -5, 1.15, -0.4], [88, 10, 1.0, -0.6],
        [93, 25, 1.05, -0.3], [87, 40, 0.95, -0.5], [95, 55, 1.1, -0.2],
        [89, 70, 1.0, -0.4]
      ];
      palmCoords.forEach(([px, pz, pScale, pRot]) => {
        const palm = createRealisticPalmTree(pScale, pRot);
        palm.position.set(px, 0.02, pz);
        beachGroup.add(palm);
      });
    }

    // Bối cảnh đô thị
    if (showUrbanContext) {
      const cityGroup = new THREE.Group();
      upperGroup.add(cityGroup);
      interactiveMeshesRef.current.set('city', cityGroup);

      const cityMat = new THREE.MeshStandardMaterial({
        color: timeMode === 'night' ? 0x0f172a : 0x64748b,
        roughness: 0.85,
        metalness: 0.05,
      });

      const blockCoords = [
        [-35, -55], [-15, -55], [5, -55], [25, -55], [45, -55],
        [-55, -35], [-55, -15], [-55, 5], [-55, 25], [-55, 45],
        [-45, 55], [-25, 55], [-5, 55], [15, 55]
      ];

      blockCoords.forEach(([bx, bz]) => {
        const bw = 12 + Math.random() * 6;
        const bd = 12 + Math.random() * 6;
        const bh = 5 + Math.random() * 12;
        const bMesh = new THREE.Mesh(new THREE.BoxGeometry(bw, bh, bd), cityMat);
        bMesh.position.set(bx, bh / 2, bz);
        bMesh.castShadow = true;
        bMesh.receiveShadow = true;
        cityGroup.add(bMesh);
      });
    }

    // Mặt đất & Trục đường giao thông
    const groundGeo = new THREE.PlaneGeometry(180, 180);
    // Cảnh quan sân vườn thảm cỏ kiến trúc PBR có sợi cỏ li ti
    const lawnTexture = createRealisticLawnTexture();
    const groundMat = new THREE.MeshStandardMaterial({
      map: lawnTexture,
      color: timeMode === 'night' ? 0x0a140e : 0x223a1f,
      roughness: 0.95,
      metalness: 0.0,
    });
    const ground = new THREE.Mesh(groundGeo, groundMat);
    ground.rotation.x = -Math.PI / 2;
    ground.receiveShadow = true;
    upperGroup.add(ground);

    const roadEastMat = new THREE.MeshStandardMaterial({
      map: createRoadMarkingTexture(true),
      roughness: 0.85,
      metalness: 0.05,
    });
    const roadSouthMat = new THREE.MeshStandardMaterial({
      map: createRoadMarkingTexture(false),
      roughness: 0.85,
      metalness: 0.05,
    });

    const roadsGroup = new THREE.Group();
    upperGroup.add(roadsGroup);
    interactiveMeshesRef.current.set('roads', roadsGroup);

    // Lòng đường đại lộ Đông & Nam
    const roadEast = new THREE.Mesh(new THREE.PlaneGeometry(16, 140), roadEastMat);
    roadEast.rotation.x = -Math.PI / 2;
    roadEast.position.set(40, 0.03, 0);
    roadsGroup.add(roadEast);

    const roadSouth = new THREE.Mesh(new THREE.PlaneGeometry(140, 16), roadSouthMat);
    roadSouth.rotation.x = -Math.PI / 2;
    roadSouth.position.set(0, 0.03, 40);
    roadsGroup.add(roadSouth);

    // VỈA HÈ LÁT ĐÁ GRANITE NÂNG CAO 0.18M (Urban Sidewalk Curbs)
    const sidewalkMat = new THREE.MeshStandardMaterial({
      color: timeMode === 'night' ? 0x1e293b : 0xd1d5db,
      roughness: 0.82,
      metalness: 0.02,
    });

    const walkEastOuter = new THREE.Mesh(new THREE.BoxGeometry(4.5, 0.18, 140), sidewalkMat);
    walkEastOuter.position.set(50.25, 0.09, 0);
    walkEastOuter.receiveShadow = true;
    roadsGroup.add(walkEastOuter);

    const walkEastInner = new THREE.Mesh(new THREE.BoxGeometry(4.0, 0.18, 140), sidewalkMat);
    walkEastInner.position.set(30, 0.09, 0);
    walkEastInner.receiveShadow = true;
    roadsGroup.add(walkEastInner);

    const walkSouthOuter = new THREE.Mesh(new THREE.BoxGeometry(140, 0.18, 4.5), sidewalkMat);
    walkSouthOuter.position.set(0, 0.09, 50.25);
    walkSouthOuter.receiveShadow = true;
    roadsGroup.add(walkSouthOuter);

    const walkSouthInner = new THREE.Mesh(new THREE.BoxGeometry(140, 0.18, 4.0), sidewalkMat);
    walkSouthInner.position.set(0, 0.09, 30);
    walkSouthInner.receiveShadow = true;
    roadsGroup.add(walkSouthInner);

    // HÀNG CÂY BÓNG MÁT ĐÔ THỊ DỌC ĐẠI LỘ (Boulevard Street Trees)
    const streetTreeCoords: [number, number, number][] = [
      [50.25, -55, 1.0], [50.25, -35, 1.1], [50.25, -15, 0.95],
      [50.25, 15, 1.05], [50.25, 35, 1.1], [50.25, 55, 0.95],
      [-55, 50.25, 1.05], [-35, 50.25, 0.95], [-15, 50.25, 1.1],
      [15, 50.25, 1.0], [35, 50.25, 1.05]
    ];
    streetTreeCoords.forEach(([tx, tz, tScale], idx) => {
      const tree = createRealisticCanopyTree(tScale, idx);
      tree.position.set(tx, 0.18, tz);
      roadsGroup.add(tree);
    });

    // Hồ nước cảnh quan (Dielectric PBR nước mặt có normal gợn sóng)
    const lakeShape = createLakeShape();
    const lakeGeo = new THREE.ShapeGeometry(lakeShape);
    const lakeMat = new THREE.MeshStandardMaterial({
      color: timeMode === 'night' ? 0x051d2d : 0x0c4a6e,
      roughness: 0.03,
      metalness: 0.02,
      normalMap: createWaterNormalTexture(),
      normalScale: new THREE.Vector2(0.35, 0.35),
      transparent: true,
      opacity: 0.9,
      envMapIntensity: 2.2,
    });
    const lakeMesh = new THREE.Mesh(lakeGeo, lakeMat);
    lakeMesh.rotation.x = -Math.PI / 2;
    lakeMesh.position.set(0, 0.08, 0);
    upperGroup.add(lakeMesh);
    interactiveMeshesRef.current.set('lake', lakeMesh);

    // CÂY BÓNG MÁT CẢNH QUAN QUANH HỒ NƯỚC (Lakefront Garden Trees)
    const lakeTreeCoords: [number, number, number][] = [
      [22, -12, 1.15], [26, 12, 1.2], [4, 26, 1.1],
      [-16, 22, 1.05], [-24, -14, 1.1], [-12, 16, 0.95]
    ];
    lakeTreeCoords.forEach(([lx, lz, lScale], idx) => {
      const lTree = createRealisticCanopyTree(lScale, idx + 7);
      lTree.position.set(lx, 0.02, lz);
      upperGroup.add(lTree);
    });

    // KHÓM CÂY BỤI CẢNH QUAN VIỀN HỒ & ĐƯỜNG DẠO BỘ (Lush Shrub Hedges)
    const hedgeMat = new THREE.MeshStandardMaterial({
      color: 0x1f421a,
      roughness: 0.9,
      metalness: 0.0,
      flatShading: true,
    });
    const hedgeCoords: [number, number, number, number, number][] = [
      [14, 18, 5, 0.8, 1.2],
      [-8, 22, 6, 0.8, 1.2],
      [20, 4, 1.2, 0.8, 6],
      [-20, 8, 1.2, 0.8, 8]
    ];
    hedgeCoords.forEach(([hx, hz, hw, hh, hd]) => {
      const hedgeMesh = new THREE.Mesh(new THREE.BoxGeometry(hw, hh, hd), hedgeMat);
      hedgeMesh.position.set(hx, hh / 2 + 0.02, hz);
      hedgeMesh.castShadow = true;
      hedgeMesh.receiveShadow = true;
      upperGroup.add(hedgeMesh);
    });

    // Quảng trường lát đá granite & Cầu gỗ tếch kiến trúc (Sheet 1)
    const plazaMesh = new THREE.Mesh(
      new THREE.CylinderGeometry(15, 15, 0.18, 48),
      new THREE.MeshStandardMaterial({
        color: timeMode === 'night' ? 0x1e293b : 0xe2e8f0,
        roughness: 0.78,
        metalness: 0.03,
      })
    );
    plazaMesh.position.set(8, 0.09, 20);
    upperGroup.add(plazaMesh);
    interactiveMeshesRef.current.set('plaza', plazaMesh);

    const bridgeCurve = new THREE.CatmullRomCurve3([
      new THREE.Vector3(20, 0.5, 10),
      new THREE.Vector3(15, 1.8, 16),
      new THREE.Vector3(6, 2.0, 20),
      new THREE.Vector3(-4, 1.5, 24),
      new THREE.Vector3(-16, 0.5, 28),
    ]);
    const bridgeMesh = new THREE.Mesh(
      new THREE.TubeGeometry(bridgeCurve, 40, 0.8, 12, false),
      new THREE.MeshStandardMaterial({ color: 0x854d0e, roughness: 0.65, metalness: 0.04 })
    );
    upperGroup.add(bridgeMesh);

    // KHỐI ĐẾ PODIUM & VÒM CONG CASSAVAS (GFRC / Đá tự nhiên travertino cao cấp)
    const podiumShape = createPodiumShape();
    const podiumHeight = 4.4;
    const podiumGeo = new THREE.ExtrudeGeometry(podiumShape, {
      depth: podiumHeight,
      bevelEnabled: true,
      bevelSegments: 4,
      bevelSize: 0.3,
      bevelThickness: 0.3,
    });
    podiumGeo.rotateX(-Math.PI / 2);

    const podiumMat = new THREE.MeshStandardMaterial({
      color: timeMode === 'night' ? 0x0f172a : 0xf1f5f9,
      roughness: 0.75,
      metalness: 0.02,
      envMapIntensity: 0.7,
      transparent: isXRayMode || isBasementView,
      opacity: isXRayMode ? 0.25 : isBasementView ? 0.12 : 0.98,
    });
    const podiumMesh = new THREE.Mesh(podiumGeo, podiumMat);
    podiumMesh.position.set(-2, 0, 2);
    podiumMesh.castShadow = true;
    podiumMesh.userData = { block: ARCHITECTURAL_BLOCKS[2] };
    upperGroup.add(podiumMesh);
    interactiveMeshesRef.current.set('podium', podiumMesh);

    // VÒM CONG CASSAVAS & MÀN HÌNH LED (Sheet 10 - Thép sơn tĩnh điện Fluoropolymer)
    const canopyCurve = new THREE.CatmullRomCurve3([
      new THREE.Vector3(-24, 0.5, 14),
      new THREE.Vector3(-20, 4.8, 12),
      new THREE.Vector3(-14, 5.2, 8),
      new THREE.Vector3(-10, 3.8, 6),
    ]);
    const canopyMesh = new THREE.Mesh(
      new THREE.TubeGeometry(canopyCurve, 32, 2.2, 16, false),
      new THREE.MeshStandardMaterial({ color: 0xf8fafc, metalness: 0.8, roughness: 0.25, envMapIntensity: 1.5 })
    );
    upperGroup.add(canopyMesh);
    interactiveMeshesRef.current.set('canopy', canopyMesh);

    const ledScreenGeo = new THREE.CylinderGeometry(4.0, 4.0, 3.2, 24, 1, true, 0, Math.PI / 2);
    const ledScreenMat = new THREE.MeshBasicMaterial({
      map: createCassavasScreenTexture(),
      side: THREE.DoubleSide,
    });
    const ledScreen = new THREE.Mesh(ledScreenGeo, ledScreenMat);
    ledScreen.position.set(-18, 3.2, 10);
    ledScreen.rotation.y = -Math.PI / 4;
    upperGroup.add(ledScreen);
    interactiveMeshesRef.current.set('ledScreen', ledScreen);

    // SẢNH CHÍNH & HỆ LAM ĐỨNG (Sheet 9 - Kính Low-E và lam nhôm Anodized)
    const lobbyEntranceGeo = new THREE.CylinderGeometry(5.5, 6.0, 3.8, 24, 1, true, -Math.PI / 3, (2 * Math.PI) / 3);
    const lobbyEntrance = new THREE.Mesh(
      lobbyEntranceGeo,
      new THREE.MeshStandardMaterial({
        color: 0x0f172a,
        metalness: 0.2,
        roughness: 0.1,
        transparent: true,
        opacity: 0.75,
        side: THREE.DoubleSide,
        envMapIntensity: 1.8,
      })
    );
    lobbyEntrance.position.set(14, 1.9, 7);
    upperGroup.add(lobbyEntrance);
    interactiveMeshesRef.current.set('lobby', lobbyEntrance);

    for (let la = -Math.PI / 3; la <= Math.PI / 3; la += 0.15) {
      const finMesh = new THREE.Mesh(
        new THREE.BoxGeometry(0.12, 3.6, 0.4),
        new THREE.MeshStandardMaterial({ color: 0x475569, metalness: 0.85, roughness: 0.3, envMapIntensity: 1.4 })
      );
      finMesh.position.set(14 + Math.sin(la) * 5.8, 1.9, 7 + Math.cos(la) * 5.8);
      finMesh.rotation.y = la;
      upperGroup.add(finMesh);
    }

    // THÁP KHÁCH SẠN 28 TẦNG (TOWER 10) - KÍNH LOW-E & MẶT ĐỰNG CURTAIN WALL PBR (Sheet 10)
    const hotelShape = createRoundedTriangleShape(7.5, 2.0);
    const hotelFloors = 28;
    const hotelHeight = 26.5;

    const hotelGeo = new THREE.ExtrudeGeometry(hotelShape, {
      depth: hotelHeight,
      bevelEnabled: true,
      bevelSegments: 5,
      bevelSize: 0.25,
      bevelThickness: 0.25,
    });
    hotelGeo.rotateX(-Math.PI / 2);

    const hotelMat = new THREE.MeshStandardMaterial({
      color: timeMode === 'night' ? 0x091422 : 0x1e293b,
      map: curtainTexture,
      roughness: 0.08,
      metalness: 0.18,
      envMapIntensity: 1.6,
      transparent: isXRayMode || isBasementView,
      opacity: isXRayMode ? 0.35 : isBasementView ? 0.12 : 0.98,
    });
    const hotelMesh = new THREE.Mesh(hotelGeo, hotelMat);
    hotelMesh.position.set(6, 0, -2);
    hotelMesh.castShadow = true;
    hotelMesh.userData = { block: ARCHITECTURAL_BLOCKS[0] };
    upperGroup.add(hotelMesh);
    interactiveMeshesRef.current.set('hotel', hotelMesh);

    // BAN CÔNG CÔNG-SON DỌC MẶT TIỀN (Sheet 10 - Bê tông sợi GFRC & Lan can kính cường lực)
    for (let bf = 4; bf < hotelFloors - 1; bf++) {
      const bY = bf * 0.94;
      const balconySlab = new THREE.Mesh(
        new THREE.BoxGeometry(2.4, 0.1, 1.6),
        new THREE.MeshStandardMaterial({ color: 0xf8fafc, roughness: 0.75, metalness: 0.02 })
      );
      balconySlab.position.set(6 - 6.8, bY, -2 + 1.2);
      upperGroup.add(balconySlab);

      const glassRailing = new THREE.Mesh(
        new THREE.BoxGeometry(2.4, 0.45, 0.05),
        new THREE.MeshStandardMaterial({
          color: 0x94a3b8,
          transparent: true,
          opacity: 0.55,
          roughness: 0.05,
          metalness: 0.15,
          envMapIntensity: 2.0,
        })
      );
      glassRailing.position.set(6 - 6.8, bY + 0.25, -2 + 1.95);
      upperGroup.add(glassRailing);
    }

    // ĐỈNH MÁI VÁT CHÉO (Sheet 10 Slanted Crown - Tấm ốp Alucobond / Titanium Zinc)
    const crownShape = createRoundedTriangleShape(6.0, 1.5);
    const crownGeo = new THREE.ExtrudeGeometry(crownShape, { depth: 3.2, bevelEnabled: true });
    crownGeo.rotateX(-Math.PI / 2);
    const crown = new THREE.Mesh(
      crownGeo,
      new THREE.MeshStandardMaterial({
        color: timeMode === 'night' ? 0x0a1424 : 0x1e293b,
        metalness: 0.85,
        roughness: 0.25,
        envMapIntensity: 1.8,
      })
    );
    crown.position.set(6, hotelHeight, -2);
    crown.rotation.z = -0.15;
    upperGroup.add(crown);
    interactiveMeshesRef.current.set('crown', crown);

    // Khe kính thông tầng
    const slotGeo = new THREE.BoxGeometry(1.6, hotelHeight - 2, 0.4);
    const slotMesh = new THREE.Mesh(
      slotGeo,
      new THREE.MeshStandardMaterial({
        color: 0x38bdf8,
        roughness: 0.05,
        metalness: 0.15,
        transparent: true,
        opacity: 0.75,
        envMapIntensity: 1.8,
      })
    );
    slotMesh.position.set(6, hotelHeight / 2, 5.6);
    upperGroup.add(slotMesh);

    // THÁP VĂN PHÒNG 12 TẦNG (TOWER 11 - Kính phản quang Silver-Blue PBR)
    const officeShape = createRoundedTriangleShape(6.2, 1.6);
    const officeHeight = 11.5;
    const officeGeo = new THREE.ExtrudeGeometry(officeShape, { depth: officeHeight, bevelEnabled: true });
    officeGeo.rotateX(-Math.PI / 2);
    const officeMat = new THREE.MeshStandardMaterial({
      color: timeMode === 'night' ? 0x081320 : 0x273549,
      map: curtainTexture,
      roughness: 0.07,
      metalness: 0.22,
      envMapIntensity: 1.6,
      transparent: isXRayMode || isBasementView,
      opacity: isXRayMode ? 0.35 : isBasementView ? 0.12 : 0.98,
    });
    const officeMesh = new THREE.Mesh(officeGeo, officeMat);
    officeMesh.position.set(-14, 0, -2);
    officeMesh.castShadow = true;
    officeMesh.userData = { block: ARCHITECTURAL_BLOCKS[1] };
    upperGroup.add(officeMesh);
    interactiveMeshesRef.current.set('office', officeMesh);

    // Cầu kính Skybridge (Kết cấu thép & kính an toàn 2 lớp)
    const skybridgeCurve = new THREE.CatmullRomCurve3([
      new THREE.Vector3(-10.5, 8.6, -2),
      new THREE.Vector3(-4.0, 8.8, -1),
      new THREE.Vector3(2.5, 8.6, -2),
    ]);
    const skybridge = new THREE.Mesh(
      new THREE.TubeGeometry(skybridgeCurve, 24, 1.2, 12, false),
      new THREE.MeshStandardMaterial({
        color: 0x0f172a,
        roughness: 0.08,
        metalness: 0.3,
        transparent: true,
        opacity: 0.75,
        envMapIntensity: 2.0,
      })
    );
    upperGroup.add(skybridge);
    interactiveMeshesRef.current.set('skybridge', skybridge);

    // BỂ BƠI VÔ CỰC TẦNG 5 (Sheet 4 - Nước phản xạ quang học thực tế)
    const poolShape = createPoolShape();
    const poolGeo = new THREE.ExtrudeGeometry(poolShape, { depth: 0.45, bevelEnabled: true });
    poolGeo.rotateX(-Math.PI / 2);
    const poolMesh = new THREE.Mesh(
      poolGeo,
      new THREE.MeshStandardMaterial({
        color: 0x0284c7,
        roughness: 0.03,
        metalness: 0.02,
        transparent: true,
        opacity: 0.88,
        envMapIntensity: 2.2,
      })
    );
    poolMesh.position.set(2, podiumHeight + 0.06, 4);
    poolMesh.userData = { block: ARCHITECTURAL_BLOCKS[3] };
    upperGroup.add(poolMesh);
    interactiveMeshesRef.current.set('pool', poolMesh);

    const poolIsland = new THREE.Mesh(
      new THREE.CylinderGeometry(1.6, 1.8, 0.4, 16),
      new THREE.MeshStandardMaterial({ color: 0x1e3a1e, roughness: 0.95, metalness: 0.0 })
    );
    poolIsland.position.set(8.5, podiumHeight + 0.25, 4.5);
    upperGroup.add(poolIsland);

    // CÂY CỌ NGHỈ DƯỠNG TRÊN ĐẢO BỂ BƠI TẦNG 5 (Rooftop Pool Resort Palms)
    const poolPalm1 = createRealisticPalmTree(0.48, 0.2);
    poolPalm1.position.set(8.2, podiumHeight + 0.45, 4.3);
    upperGroup.add(poolPalm1);

    const poolPalm2 = createRealisticPalmTree(0.42, -0.6);
    poolPalm2.position.set(8.8, podiumHeight + 0.45, 4.8);
    upperGroup.add(poolPalm2);

    // HẦM B1-B2 (Bê tông kết cấu chống thấm)
    const basementSlab = new THREE.Mesh(
      new THREE.BoxGeometry(58, 1.2, 52),
      new THREE.MeshStandardMaterial({ color: 0x334155, roughness: 0.9, metalness: 0.02 })
    );
    basementSlab.position.set(-2, -3.6, 4);
    basementGroup.add(basementSlab);

    // =========================================================================
    // GROUP B: NỘI THẤT VIP SUITE 3D (Sheet 9)
    // =========================================================================
    const vipInteriorGroup = new THREE.Group();
    scene.add(vipInteriorGroup);
    vipInteriorGroupRef.current = vipInteriorGroup;
    vipInteriorGroup.visible = false;

    // Sàn gạch men xám
    const floorMat = new THREE.MeshStandardMaterial({ color: 0xcbd5e1, roughness: 0.2, metalness: 0.1 });
    const suiteFloor = new THREE.Mesh(new THREE.PlaneGeometry(12, 10), floorMat);
    suiteFloor.rotation.x = -Math.PI / 2;
    suiteFloor.receiveShadow = true;
    vipInteriorGroup.add(suiteFloor);

    // Thảm lông xám
    const suiteRug = new THREE.Mesh(new THREE.PlaneGeometry(6.5, 4.5), new THREE.MeshStandardMaterial({ color: 0x334155, roughness: 0.9 }));
    suiteRug.rotation.x = -Math.PI / 2;
    suiteRug.position.set(0, 0.02, 0);
    suiteRug.receiveShadow = true;
    vipInteriorGroup.add(suiteRug);

    // Vách đá Marble Ý & Nẹp vàng PVD (Sheet 9)
    const marbleWall = new THREE.Mesh(new THREE.PlaneGeometry(12, 4.2), new THREE.MeshStandardMaterial({
      map: createMarbleWallTexture(),
      roughness: 0.2,
      metalness: 0.25,
    }));
    marbleWall.position.set(0, 2.1, -5.0);
    vipInteriorGroup.add(marbleWall);

    // Kệ tủ treo tường & Tivi
    const shelf = new THREE.Mesh(new THREE.BoxGeometry(8.5, 0.35, 0.7), new THREE.MeshStandardMaterial({ color: 0x78350f, roughness: 0.6 }));
    shelf.position.set(0, 0.8, -4.6);
    vipInteriorGroup.add(shelf);

    const tv = new THREE.Mesh(new THREE.PlaneGeometry(3.6, 2.1), new THREE.MeshBasicMaterial({ color: 0x090d16 }));
    tv.position.set(0, 2.5, -4.95);
    vipInteriorGroup.add(tv);

    // ĐÈN CHÙM THẢ TRẦN RING LED (Sheet 9)
    const ring1 = new THREE.Mesh(new THREE.TorusGeometry(1.1, 0.04, 16, 48), new THREE.MeshBasicMaterial({ color: 0xfef08a }));
    ring1.position.set(0, 3.2, 0);
    ring1.rotation.x = Math.PI / 4;
    vipInteriorGroup.add(ring1);

    const ring2 = new THREE.Mesh(new THREE.TorusGeometry(0.8, 0.04, 16, 48), new THREE.MeshBasicMaterial({ color: 0xffffff }));
    ring2.position.set(0.3, 3.0, 0.2);
    ring2.rotation.y = Math.PI / 3;
    vipInteriorGroup.add(ring2);

    const chandelierLight = new THREE.PointLight(0xfef08a, 1.8, 12);
    chandelierLight.position.set(0, 2.9, 0);
    vipInteriorGroup.add(chandelierLight);

    // SOFA CHỮ L & ĐÔN HOUNDSTOOTH (Sheet 9)
    const sofaMain = new THREE.Mesh(new THREE.BoxGeometry(3.8, 0.65, 1.1), new THREE.MeshStandardMaterial({ color: 0xe2e8f0, roughness: 0.8 }));
    sofaMain.position.set(0, 0.45, -1.2);
    vipInteriorGroup.add(sofaMain);

    const sofaL = new THREE.Mesh(new THREE.BoxGeometry(1.1, 0.65, 1.8), new THREE.MeshStandardMaterial({ color: 0xe2e8f0, roughness: 0.8 }));
    sofaL.position.set(1.4, 0.45, 0.2);
    vipInteriorGroup.add(sofaL);

    const houndstoothOttoman = new THREE.Mesh(new THREE.BoxGeometry(1.2, 0.45, 1.2), new THREE.MeshStandardMaterial({
      map: createHoundstoothTexture(),
      roughness: 0.9,
    }));
    houndstoothOttoman.position.set(-1.6, 0.35, -0.6);
    vipInteriorGroup.add(houndstoothOttoman);

    // Tượng chú cún Magis Puppy (Sheet 9)
    const puppyBody = new THREE.Mesh(new THREE.CylinderGeometry(0.2, 0.25, 0.6, 12), new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 0.1, metalness: 0.1 }));
    puppyBody.rotation.z = Math.PI / 2;
    puppyBody.position.set(-1.8, 0.35, 0.6);
    vipInteriorGroup.add(puppyBody);

    // Ghế thư giãn da bò Cognac & Đèn cây
    const armchair = new THREE.Mesh(new THREE.BoxGeometry(1.1, 0.95, 1.1), new THREE.MeshStandardMaterial({ color: 0x9a3412, roughness: 0.4 }));
    armchair.position.set(3.2, 0.6, 1.2);
    armchair.rotation.y = -Math.PI / 4;
    vipInteriorGroup.add(armchair);

    const ottoman = new THREE.Mesh(new THREE.BoxGeometry(0.7, 0.35, 0.7), new THREE.MeshStandardMaterial({ color: 0x9a3412, roughness: 0.4 }));
    ottoman.position.set(2.4, 0.3, 0.4);
    ottoman.rotation.y = -Math.PI / 4;
    vipInteriorGroup.add(ottoman);

    const lampPole = new THREE.Mesh(new THREE.CylinderGeometry(0.02, 0.02, 2.0), new THREE.MeshStandardMaterial({ color: 0x0f172a }));
    lampPole.position.set(3.8, 1.0, 1.8);
    vipInteriorGroup.add(lampPole);

    // Bàn trà đôi tròn
    const table1 = new THREE.Mesh(new THREE.CylinderGeometry(0.7, 0.7, 0.35, 32), new THREE.MeshStandardMaterial({ color: 0xf8fafc, roughness: 0.1 }));
    table1.position.set(-0.2, 0.25, 0.2);
    vipInteriorGroup.add(table1);

    const table2 = new THREE.Mesh(new THREE.CylinderGeometry(0.5, 0.5, 0.45, 32), new THREE.MeshStandardMaterial({ color: 0x1e293b, roughness: 0.4 }));
    table2.position.set(0.6, 0.3, 0.5);
    vipInteriorGroup.add(table2);

    // Ban công kính hướng biển Đà Nẵng (Sheet 9)
    const glassDoor = new THREE.Mesh(new THREE.PlaneGeometry(10, 4.0), new THREE.MeshStandardMaterial({
      color: 0x94a3b8,
      transparent: true,
      opacity: 0.35,
      roughness: 0.05,
      metalness: 0.15,
      envMapIntensity: 1.8,
    }));
    glassDoor.position.set(0, 2.0, 4.8);
    vipInteriorGroup.add(glassDoor);

    const balconyFloor = new THREE.Mesh(new THREE.BoxGeometry(10, 0.2, 2.5), new THREE.MeshStandardMaterial({ color: 0x78350f, roughness: 0.75, metalness: 0.0 }));
    balconyFloor.position.set(0, -0.05, 6.0);
    vipInteriorGroup.add(balconyFloor);

    const balconyGlass = new THREE.Mesh(new THREE.BoxGeometry(10, 1.1, 0.05), new THREE.MeshStandardMaterial({
      color: 0x94a3b8,
      transparent: true,
      opacity: 0.55,
      roughness: 0.05,
      metalness: 0.1,
      envMapIntensity: 2.0,
    }));
    balconyGlass.position.set(0, 0.6, 7.2);
    vipInteriorGroup.add(balconyGlass);

    const suiteOcean = new THREE.Mesh(new THREE.PlaneGeometry(60, 40), new THREE.MeshStandardMaterial({
      color: 0x0b3d59,
      roughness: 0.04,
      metalness: 0.02,
      envMapIntensity: 2.0,
    }));
    suiteOcean.rotation.x = -Math.PI / 2;
    suiteOcean.position.set(0, -3.0, 24);
    vipInteriorGroup.add(suiteOcean);

    // =========================================================================
    // GROUP C: MẶT BẰNG TẦNG ĐIỂN HÌNH (Sheet 10)
    // =========================================================================
    const typicalPlateGroup = new THREE.Group();
    scene.add(typicalPlateGroup);
    typicalPlateGroupRef.current = typicalPlateGroup;
    typicalPlateGroup.visible = false;

    const plateShape = createRoundedTriangleShape(12, 3.0);
    const plateGeo = new THREE.ExtrudeGeometry(plateShape, { depth: 0.4, bevelEnabled: true });
    plateGeo.rotateX(-Math.PI / 2);
    const plateMesh = new THREE.Mesh(plateGeo, new THREE.MeshStandardMaterial({ color: 0x334155, roughness: 0.75, metalness: 0.05 }));
    typicalPlateGroup.add(plateMesh);

    // Lõi dịch vụ tam giác & 3 thang máy cam
    const coreShape = createRoundedTriangleShape(4.0, 0.8);
    const coreGeo = new THREE.ExtrudeGeometry(coreShape, { depth: 2.2, bevelEnabled: false });
    coreGeo.rotateX(-Math.PI / 2);
    const coreMesh = new THREE.Mesh(coreGeo, new THREE.MeshStandardMaterial({ color: 0xe05638, roughness: 0.6, metalness: 0.1 }));
    coreMesh.position.set(0, 0.4, 0);
    typicalPlateGroup.add(coreMesh);

    // 3 Căn VIP tại 3 góc
    const vipAngles = [Math.PI / 2, Math.PI / 2 + (2 * Math.PI) / 3, Math.PI / 2 + (4 * Math.PI) / 3];
    vipAngles.forEach((ang) => {
      const vx = Math.cos(ang) * 9.5;
      const vz = Math.sin(ang) * 9.5;
      const vipZone = new THREE.Mesh(
        new THREE.CylinderGeometry(2.4, 2.4, 1.2, 24),
        new THREE.MeshStandardMaterial({ color: 0x38bdf8, roughness: 0.2, metalness: 0.1, transparent: true, opacity: 0.45 })
      );
      vipZone.position.set(vx, 0.8, vz);
      typicalPlateGroup.add(vipZone);
    });

    // Ghim chú thích (Đã loại bỏ các cột tròn vàng ghim đồ chơi nhựa để đảm bảo phối cảnh PBR chân thực)
    pinObjectsRef.current.clear();

    // Raycasting & Click
    const raycaster = new THREE.Raycaster();
    const mouse = new THREE.Vector2();

    const handleCanvasClick = (event: MouseEvent) => {
      const rect = canvas.getBoundingClientRect();
      mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
      mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;

      raycaster.setFromCamera(mouse, camera);

      // Pascal 3D Measure Tool Raycasting
      if (isMeasuring) {
        const allMeshes: THREE.Object3D[] = [];
        scene.traverse((obj) => {
          if (obj instanceof THREE.Mesh && obj.visible) allMeshes.push(obj);
        });
        const measureHits = raycaster.intersectObjects(allMeshes);
        if (measureHits.length > 0) {
          const pt = measureHits[0].point;
          if (measureStep === 0 || !measureStartPointRef.current) {
            measureStartPointRef.current = [pt.x, pt.y, pt.z];
            setMeasureStep(1);
          } else {
            const start = measureStartPointRef.current;
            const end: [number, number, number] = [pt.x, pt.y, pt.z];
            const dist = Math.hypot(end[0] - start[0], end[1] - start[1], end[2] - start[2]) * 2.5;
            setCurrentMeasurement({
              id: `measure-${Date.now()}`,
              start,
              end,
              distanceMeters: dist,
            });
            setMeasureStep(2);
            measureStartPointRef.current = null;
          }
          return;
        }
      }

      if (activeTab === 'overview' && showPins) {
        const pinMeshes: THREE.Object3D[] = [];
        pinObjectsRef.current.forEach((g) => {
          g.traverse((c) => {
            if (c instanceof THREE.Mesh) pinMeshes.push(c);
          });
        });
        const pinHits = raycaster.intersectObjects(pinMeshes);
        if (pinHits.length > 0) {
          let cur: THREE.Object3D | null = pinHits[0].object;
          while (cur && !cur.userData?.pin) {
            cur = cur.parent;
          }
          if (cur?.userData?.pin) {
            const hitPin = cur.userData.pin as SitePin;
            setSelectedPin(hitPin);
            targetLookAtRef.current.set(hitPin.pos[0], Math.max(2, hitPin.pos[1]), hitPin.pos[2]);

            // Populate Pascal BIM Inspector for Pin
            setInspectorData({
              id: `pin-${hitPin.id}`,
              code: `PIN-${hitPin.id}`,
              name: hitPin.title,
              category: hitPin.category === 'tower' ? 'tower' : hitPin.category === 'amenity' ? 'amenity' : 'zone',
              properties: [
                { label: 'Phân loại', value: hitPin.category.toUpperCase() },
                { label: 'Vị trí X, Y, Z', value: `${hitPin.pos[0]}, ${hitPin.pos[1]}, ${hitPin.pos[2]}` },
              ],
              description: hitPin.subtitle,
            });
            return;
          }
        }
      }

      if (activeTab === 'overview') {
        const meshes = Array.from(interactiveMeshesRef.current.values());
        const intersects = raycaster.intersectObjects(meshes);
        if (intersects.length > 0) {
          const hit = intersects[0].object;
          const block = hit.userData?.block as BuildingBlockData | undefined;
          if (block) {
            setSelectedBlock(block);
            onSelectBlock?.(block);

            // Populate Pascal BIM Inspector for Block
            setInspectorData({
              id: block.id,
              code: block.code,
              name: block.name,
              category: block.category,
              elevation: block.floors ? `+0.000m -> +${(block.floors * 3.4).toFixed(1)}m` : undefined,
              areaM2: block.floors ? block.floors * 1250 : undefined,
              properties: [
                { label: 'Số tầng cao', value: `${block.floors} Tầng` },
                { label: 'Số căn hộ', value: block.apartments },
                { label: 'Cư dân & Khách', value: `${block.residents} người` },
                { label: 'Tỷ lệ lấp đầy', value: `${block.occupancyRate}%`, status: 'success' },
                { label: 'Công suất điện', value: block.powerKw, unit: 'kW' },
                { label: 'Nhiệt độ kỹ thuật', value: block.temp, unit: '°C' },
                { label: 'Trạng thái vận hành', value: block.status },
              ],
              description: block.description,
            });
          }
        }
      }
    };

    canvas.addEventListener('click', handleCanvasClick);

    // Orbit Drag & Zoom
    const handleMouseDown = (e: MouseEvent) => {
      isDraggingRef.current = true;
      prevMouseRef.current = { x: e.clientX, y: e.clientY };
    };

    const handleMouseMove = (e: MouseEvent) => {
      if (!isDraggingRef.current) return;
      const deltaX = e.clientX - prevMouseRef.current.x;
      const deltaY = e.clientY - prevMouseRef.current.y;
      prevMouseRef.current = { x: e.clientX, y: e.clientY };

      orbitAngleRef.current.theta -= deltaX * 0.007;
      orbitAngleRef.current.phi = Math.max(
        0.04,
        Math.min(Math.PI / 2 - 0.03, orbitAngleRef.current.phi - deltaY * 0.007)
      );
      updateCameraPos();
    };

    const handleMouseUp = () => {
      isDraggingRef.current = false;
    };

    const handleWheel = (e: WheelEvent) => {
      e.preventDefault();
      const minR = activeTab === 'vip_suite' ? 3 : 18;
      const maxR = activeTab === 'vip_suite' ? 18 : 150;
      orbitAngleRef.current.radius = Math.max(
        minR,
        Math.min(maxR, orbitAngleRef.current.radius + e.deltaY * 0.07)
      );
      updateCameraPos();
    };

    canvas.addEventListener('mousedown', handleMouseDown);
    window.addEventListener('mousemove', handleMouseMove);
    window.addEventListener('mouseup', handleMouseUp);
    canvas.addEventListener('wheel', handleWheel, { passive: false });

    let animationFrameId: number;
    const clock = new THREE.Clock();

    const animate = () => {
      animationFrameId = requestAnimationFrame(animate);
      const elapsedTime = clock.getElapsedTime();

      // A3D Shading Mode Override
      if (shadingMode === 'clay') {
        scene.overrideMaterial = clayMatRef.current;
      } else if (shadingMode === 'wireframe') {
        scene.overrideMaterial = wireframeMatRef.current;
      } else if (shadingMode === 'depth') {
        scene.overrideMaterial = depthMatRef.current;
      } else {
        scene.overrideMaterial = null;
      }

      // A3D Dynamic Sun & Atmosphere
      if (sunLightRef.current) {
        const radElev = THREE.MathUtils.degToRad(envSettings.sunElevation);
        const radAzim = THREE.MathUtils.degToRad(envSettings.sunAzimuth);
        const sunDist = 90;
        sunLightRef.current.position.set(
          sunDist * Math.cos(radElev) * Math.sin(radAzim),
          sunDist * Math.sin(radElev),
          sunDist * Math.cos(radElev) * Math.cos(radAzim)
        );
        sunLightRef.current.intensity = envSettings.sunIntensity;
      }
      if (ambientLightRef.current) {
        ambientLightRef.current.intensity = envSettings.ambientIntensity;
      }
      if (gridHelperRef.current) {
        gridHelperRef.current.visible = envSettings.showWorldGrid;
      }
      if (scene.fog instanceof THREE.FogExp2) {
        scene.fog.density = envSettings.fogDensity;
      }

      if (isAutoRotate && !isDraggingRef.current) {
        orbitAngleRef.current.theta += 0.002;
        updateCameraPos();
      }

      if (oceanMeshRef.current && oceanMeshRef.current.material instanceof THREE.MeshStandardMaterial) {
        oceanMeshRef.current.material.roughness = 0.05 + Math.sin(elapsedTime * 1.5) * 0.02;
      }

      if (showPins && activeTab === 'overview') {
        pinObjectsRef.current.forEach((g, idx) => {
          g.position.y = SITE_PINS[idx - 1]?.pos[1] + Math.sin(elapsedTime * 3 + idx) * 0.2;
        });
      }

      // Pascal Architectural Level System (Exploded / Solo / Stacked)
      const hotelMesh = interactiveMeshesRef.current.get('hotel');
      const crownMesh = interactiveMeshesRef.current.get('crown');
      const poolMesh = interactiveMeshesRef.current.get('pool');
      const podiumMesh = interactiveMeshesRef.current.get('podium');
      const officeMesh = interactiveMeshesRef.current.get('office');

      if (pascalLevelMode === 'exploded') {
        const explodeOffset = (pascalExplodeFactor - 1.0) * 8.5;
        if (hotelMesh) hotelMesh.position.y = explodeOffset * 1.0;
        if (crownMesh) crownMesh.position.y = 26.5 + explodeOffset * 1.25;
        if (officeMesh) officeMesh.position.y = explodeOffset * 0.65;
        if (poolMesh) poolMesh.position.y = 4.8 + explodeOffset * 0.35;
        if (podiumMesh) podiumMesh.position.y = 0;
      } else if (pascalLevelMode === 'solo') {
        if (hotelMesh) hotelMesh.position.y = 0;
        if (crownMesh) crownMesh.position.y = 26.5;
        if (officeMesh) officeMesh.position.y = 0;
        if (poolMesh) poolMesh.position.y = 4.8;
        if (podiumMesh) podiumMesh.position.y = 0;

        if (pascalSoloLevel === 'Tầng 4-5') {
          if (poolMesh) poolMesh.visible = true;
          if (hotelMesh) hotelMesh.visible = false;
          if (officeMesh) officeMesh.visible = false;
        } else if (pascalSoloLevel === 'Tầng 6-28') {
          if (hotelMesh) hotelMesh.visible = true;
          if (officeMesh) officeMesh.visible = false;
          if (poolMesh) poolMesh.visible = false;
        } else if (pascalSoloLevel === 'B1-B2') {
          if (hotelMesh) hotelMesh.visible = false;
          if (officeMesh) officeMesh.visible = false;
          if (poolMesh) poolMesh.visible = false;
        }
      } else {
        // Stacked Mode
        if (hotelMesh) { hotelMesh.position.y = 0; hotelMesh.visible = true; }
        if (crownMesh) { crownMesh.position.y = 26.5; crownMesh.visible = true; }
        if (officeMesh) { officeMesh.position.y = 0; officeMesh.visible = true; }
        if (poolMesh) { poolMesh.position.y = 4.8; poolMesh.visible = true; }
        if (podiumMesh) { podiumMesh.position.y = 0; podiumMesh.visible = true; }
      }

      renderer.render(scene, camera);
    };
    animate();

    const handleResize = () => {
      if (!containerRef.current) return;
      const w = containerRef.current.clientWidth;
      const h = containerRef.current.clientHeight;
      setContainerDims({ width: w, height: h });
      camera.aspect = w / h;
      camera.updateProjectionMatrix();
      renderer.setSize(w, h);
    };

    const resizeObserver = new ResizeObserver(handleResize);
    resizeObserver.observe(container);

    return () => {
      cancelAnimationFrame(animationFrameId);
      resizeObserver.disconnect();
      canvas.removeEventListener('click', handleCanvasClick);
      canvas.removeEventListener('mousedown', handleMouseDown);
      window.removeEventListener('mousemove', handleMouseMove);
      window.removeEventListener('mouseup', handleMouseUp);
      canvas.removeEventListener('wheel', handleWheel);
      curtainTexture.dispose();
      clayMatRef.current?.dispose();
      wireframeMatRef.current?.dispose();
      depthMatRef.current?.dispose();
      gridHelperRef.current?.dispose();
      renderer.dispose();
    };
  }, [timeMode, isXRayMode, isBasementView, showUrbanContext, showBeach, isAutoRotate, showPins, activeTab, shadingMode, envSettings]);

  const handleClientSnapshot = () => {
    if (!canvasRef.current) return;
    const dataUrl = canvasRef.current.toDataURL('image/png');
    const a = document.createElement('a');
    a.href = dataUrl;
    a.download = `cassavas_architectural_twin_${activeTab}.png`;
    a.click();
  };

  return (
    <div
      className={`relative flex flex-col w-full rounded-3xl overflow-hidden bg-slate-950 text-white shadow-2xl border border-slate-800 transition-all duration-300 ${
        isFullscreen ? 'fixed inset-0 z-50 rounded-none h-screen' : `h-[740px] sm:h-[780px] ${className}`
      }`}
    >
      {/* ================= 1. THANH TIÊU ĐỀ & TRẠNG THÁI HUD CHUẨN BIM ================= */}
      <div className="absolute top-3 left-3 right-3 z-30 flex flex-wrap items-center justify-between gap-2.5 pointer-events-none">
        <div className="flex items-center gap-3 px-4 py-2.5 rounded-2xl bg-slate-900/90 backdrop-blur-xl border border-white/10 shadow-2xl pointer-events-auto">
          <div className="w-9 h-9 rounded-xl bg-gradient-to-tr from-sky-600 via-indigo-600 to-cyan-500 flex items-center justify-center text-white shadow-md shadow-sky-500/25">
            <Building2 className="w-5 h-5" />
          </div>
          <div>
            <div className="flex items-center gap-2">
              <span className="text-xs font-black tracking-tight text-white uppercase font-mono">
                CASSAVAS COMPLEX DA NANG • 28T DIGITAL TWIN
              </span>
              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 font-mono">
                <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse" />
                TCVN 06:2022 • LOD 350
              </span>
            </div>
            <p className="text-[11px] text-slate-400 font-sans">
              Mặt Đứng Tây - Đông • Tháp 28T Mái Vát • Vòm Cassavas & LED Wall • Nội Thất Suite VIP
            </p>
          </div>
        </div>

        {/* Chuyển đổi WebGL / Server Snapshot & Nút Phóng to màn hình */}
        <div className="flex items-center gap-2 pointer-events-auto">
          <div className="p-1 rounded-2xl bg-slate-900/90 backdrop-blur-xl border border-white/10 flex items-center gap-1 text-xs">
            <button
              type="button"
              onClick={() => setViewMode('interactive')}
              className={`flex items-center gap-1.5 px-3 py-1.5 rounded-xl font-semibold transition-all cursor-pointer ${
                viewMode === 'interactive' ? 'bg-sky-600 text-white shadow-md' : 'text-slate-400 hover:text-white'
              }`}
            >
              <Eye className="w-3.5 h-3.5" />
              <span className="hidden sm:inline">WebGL 3D Trực Tuyến</span>
            </button>
            <button
              type="button"
              onClick={() => setViewMode('server_snapshot')}
              className={`flex items-center gap-1.5 px-3 py-1.5 rounded-xl font-semibold transition-all cursor-pointer ${
                viewMode === 'server_snapshot' ? 'bg-sky-600 text-white shadow-md' : 'text-slate-400 hover:text-white'
              }`}
            >
              <Server className="w-3.5 h-3.5" />
              <span className="hidden sm:inline">Ảnh Server</span>
            </button>
          </div>

          <button
            type="button"
            onClick={handleTriggerServerRender}
            disabled={isRenderingServer}
            title="Kết xuất WebGL ngầm trên máy chủ"
            className="flex items-center gap-1.5 px-3 py-2 rounded-2xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white text-xs font-semibold shadow-lg shadow-emerald-500/20 transition-all cursor-pointer disabled:opacity-50"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${isRenderingServer ? 'animate-spin' : ''}`} />
            <span className="hidden md:inline">Render GPU</span>
          </button>

          <button
            type="button"
            onClick={() => setIsFullscreen(!isFullscreen)}
            title={isFullscreen ? 'Thu nhỏ cửa sổ' : 'Xem toàn màn hình'}
            className="p-2.5 rounded-2xl bg-slate-900/90 backdrop-blur-xl border border-white/10 text-slate-300 hover:text-white shadow-lg transition-all cursor-pointer"
          >
            {isFullscreen ? <Minimize2 className="w-4 h-4 text-sky-400" /> : <Maximize2 className="w-4 h-4 text-sky-400" />}
          </button>
        </div>
      </div>

      {/* ================= 2. THANH TABS ĐIỀU HƯỚNG CHÍNH ================= */}
      {viewMode === 'interactive' && (
        <div className="absolute top-16 left-3 z-30 flex flex-wrap items-center gap-2">
          <div className="p-1 rounded-2xl bg-slate-900/90 backdrop-blur-xl border border-white/10 shadow-xl flex items-center gap-1">
            <button
              type="button"
              onClick={() => setActiveTab('overview')}
              className={`flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer ${
                activeTab === 'overview' ? 'bg-sky-600 text-white shadow-md' : 'text-slate-400 hover:text-white'
              }`}
            >
              <Compass className="w-3.5 h-3.5 text-sky-300" />
              <span>🌐 Ngoại Thất Đồ Án</span>
            </button>
            <button
              type="button"
              onClick={() => setActiveTab('vip_suite')}
              className={`flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer ${
                activeTab === 'vip_suite' ? 'bg-amber-600 text-white shadow-md' : 'text-slate-400 hover:text-white'
              }`}
            >
              <Armchair className="w-3.5 h-3.5 text-amber-300" />
              <span>🛋️ Suite VIP 3D (Sheet 9)</span>
            </button>
            <button
              type="button"
              onClick={() => setActiveTab('typical_plate')}
              className={`flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer ${
                activeTab === 'typical_plate' ? 'bg-teal-600 text-white shadow-md' : 'text-slate-400 hover:text-white'
              }`}
            >
              <LayoutGrid className="w-3.5 h-3.5 text-teal-300" />
              <span>📐 Tầng Điển Hình (Sheet 10)</span>
            </button>
            <button
              type="button"
              onClick={() => setActiveTab('floors')}
              className={`flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer ${
                activeTab === 'floors' ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-400 hover:text-white'
              }`}
            >
              <Layers className="w-3.5 h-3.5 text-indigo-300" />
              <span>🏢 Cốt Tầng BIM</span>
            </button>
          </div>

          {/* Chọn tầng BIM */}
          {activeTab === 'floors' && (
            <div className="flex flex-wrap items-center gap-1 p-1 rounded-2xl bg-slate-900/85 backdrop-blur-xl border border-white/10 shadow-xl">
              {FLOOR_LEVELS.map((fl) => (
                <button
                  key={fl.level}
                  type="button"
                  onClick={() => handleSelectFloor(fl)}
                  className={`px-2.5 py-1 rounded-xl text-[11px] font-bold transition-all cursor-pointer ${
                    selectedFloor.level === fl.level ? 'bg-sky-600 text-white shadow-md' : 'text-slate-400 hover:text-white'
                  }`}
                >
                  {fl.level}
                </button>
              ))}
            </div>
          )}
        </div>
      )}

      {/* ================= 3. THANH ĐIỀU KHIỂN GÓC NHÌN BẢN VẼ (LEFT FLOATING TOOLBAR) ================= */}
      {viewMode === 'interactive' && activeTab === 'overview' && (
        <div className="absolute top-28 left-3 z-20 flex flex-col gap-1 p-1.5 rounded-2xl bg-slate-900/90 backdrop-blur-xl border border-white/10 shadow-2xl">
          <span className="px-2 py-1 text-[9px] font-mono font-bold text-slate-400 uppercase tracking-widest border-b border-white/10">
            Góc Chiếu Chuẩn
          </span>
          <button
            type="button"
            onClick={() => setCameraPreset('master')}
            className="flex items-center gap-2 px-2.5 py-1.5 rounded-xl text-xs font-medium text-slate-300 hover:text-white hover:bg-white/10 transition-all cursor-pointer"
          >
            <span>🌐 Toàn cảnh ISO</span>
          </button>
          <button
            type="button"
            onClick={() => setCameraPreset('elev_south')}
            className="flex items-center gap-2 px-2.5 py-1.5 rounded-xl text-xs font-medium text-sky-300 hover:text-white hover:bg-white/10 transition-all cursor-pointer"
          >
            <span>🧭 Mặt Đứng Nam (1/150)</span>
          </button>
          <button
            type="button"
            onClick={() => setCameraPreset('elev_west')}
            className="flex items-center gap-2 px-2.5 py-1.5 rounded-xl text-xs font-medium text-indigo-300 hover:text-white hover:bg-white/10 transition-all cursor-pointer"
          >
            <span>🌅 Mặt Đứng Tây (1/150)</span>
          </button>
          <button
            type="button"
            onClick={() => setCameraPreset('hotel')}
            className="flex items-center gap-2 px-2.5 py-1.5 rounded-xl text-xs font-medium text-cyan-300 hover:text-white hover:bg-white/10 transition-all cursor-pointer"
          >
            <span>🏨 Tháp 28T Mái Vát</span>
          </button>
          <button
            type="button"
            onClick={() => setCameraPreset('office')}
            className="flex items-center gap-2 px-2.5 py-1.5 rounded-xl text-xs font-medium text-purple-300 hover:text-white hover:bg-white/10 transition-all cursor-pointer"
          >
            <span>🏢 Vòm Cassavas & LED</span>
          </button>
          <button
            type="button"
            onClick={() => setCameraPreset('podium')}
            className="flex items-center gap-2 px-2.5 py-1.5 rounded-xl text-xs font-medium text-teal-300 hover:text-white hover:bg-white/10 transition-all cursor-pointer"
          >
            <span>🏊 Bể Bơi T5 Ốc Đảo</span>
          </button>
          <button
            type="button"
            onClick={() => setCameraPreset('basement')}
            className="flex items-center gap-2 px-2.5 py-1.5 rounded-xl text-xs font-medium text-amber-300 hover:text-white hover:bg-white/10 transition-all cursor-pointer"
          >
            <span>🚗 Hầm B1-B2 & Sạc EV</span>
          </button>
          <button
            type="button"
            onClick={() => setCameraPreset('beach')}
            className="flex items-center gap-2 px-2.5 py-1.5 rounded-xl text-xs font-medium text-emerald-300 hover:text-white hover:bg-white/10 transition-all cursor-pointer"
          >
            <span>🌊 View Biển Đà Nẵng</span>
          </button>
        </div>
      )}

      {/* ================= 4. KHUNG CANVAS VIEWPORT CHÍNH ================= */}
      <div ref={containerRef} className="relative flex-1 w-full h-full min-h-[500px]">
        {viewMode === 'interactive' ? (
          <>
            <canvas ref={canvasRef} className="w-full h-full cursor-grab active:cursor-grabbing block" />

            {/* A3D Aspect Ratio Framing Overlay */}
            <A3DRatioOverlay
              ratio={aspectRatio}
              containerWidth={containerDims.width}
              containerHeight={containerDims.height}
            />

            {/* A3D Scene Outliner Drawer */}
            {activeA3DDrawer === 'outliner' && (
              <div className="absolute top-28 left-4 z-40">
                <A3DSceneOutliner
                  items={outlinerItems}
                  onToggleVisibility={handleToggleOutlinerItem}
                  onFocusItem={handleFocusOutlinerItem}
                  onClose={() => setActiveA3DDrawer('none')}
                />
              </div>
            )}

            {/* A3D Environment Panel Drawer */}
            {activeA3DDrawer === 'environment' && (
              <div className="absolute top-28 left-4 z-40">
                <A3DEnvironmentPanel
                  settings={envSettings}
                  timeMode={timeMode}
                  onChangeSettings={(ns) => setEnvSettings((prev) => ({ ...prev, ...ns }))}
                  onChangeTimeMode={setTimeMode}
                  onClose={() => setActiveA3DDrawer('none')}
                />
              </div>
            )}

            {/* A3D AI Render Studio Drawer */}
            {activeA3DDrawer === 'ai_studio' && (
              <div className="absolute top-28 right-4 z-40">
                <A3DAiRenderStudio
                  onCaptureSnapshot={handleClientSnapshot}
                  onCaptureDepthMap={handleCaptureDepthMap}
                  onSetShadingMode={setShadingMode}
                  currentShadingMode={shadingMode}
                  onClose={() => setActiveA3DDrawer('none')}
                />
              </div>
            )}

            {/* Pascal Level System Control Drawer */}
            {activeA3DDrawer === 'level_control' && (
              <div className="absolute top-28 left-4 z-40">
                <PascalLevelControl
                  levelMode={pascalLevelMode}
                  setLevelMode={setPascalLevelMode}
                  explodeFactor={pascalExplodeFactor}
                  setExplodeFactor={setPascalExplodeFactor}
                  selectedLevel={pascalSoloLevel}
                  setSelectedLevel={(lvl) => {
                    setPascalSoloLevel(lvl);
                    const match = FLOOR_LEVELS.find((fl) => fl.level === lvl);
                    if (match) handleSelectFloor(match);
                  }}
                  floors={FLOOR_LEVELS}
                />
              </div>
            )}

            {/* Pascal Interactive 3D Measurement Overlay */}
            <PascalMeasureOverlay
              isMeasuring={isMeasuring}
              measurement={currentMeasurement}
              measureStep={measureStep}
              onResetMeasurement={() => {
                setMeasureStep(0);
                setCurrentMeasurement(null);
                measureStartPointRef.current = null;
              }}
              onToggleMeasure={() => {
                setIsMeasuring((prev) => !prev);
                setMeasureStep(0);
                setCurrentMeasurement(null);
                measureStartPointRef.current = null;
              }}
            />

            {/* Pascal Architectural Property & BIM Inspector */}
            {inspectorData && (
              <div className="absolute top-28 right-4 z-40">
                <PascalBimInspector
                  data={inspectorData}
                  onClose={() => setInspectorData(null)}
                  onFocus={() => {
                    const block = ARCHITECTURAL_BLOCKS.find((b) => b.id === inspectorData.id);
                    if (block) {
                      setSelectedBlock(block);
                      onSelectBlock?.(block);
                    }
                  }}
                />
              </div>
            )}

            {/* Pascal Agent & MCP Command Console */}
            {isAgentConsoleOpen && (
              <div className="absolute bottom-20 left-1/2 -translate-x-1/2 z-40 w-[95%] max-w-xl">
                <PascalAgentConsole
                  isOpen={isAgentConsoleOpen}
                  onClose={() => setIsAgentConsoleOpen(false)}
                  onExecuteCommand={handleExecutePascalCommand}
                />
              </div>
            )}

            {/* A3D & Pascal Floating Studio Dock */}
            <A3DStudioDock
              activeDrawer={activeA3DDrawer}
              onToggleDrawer={(d) => setActiveA3DDrawer(activeA3DDrawer === d ? 'none' : d)}
              shadingMode={shadingMode}
              onChangeShadingMode={setShadingMode}
              aspectRatio={aspectRatio}
              onChangeAspectRatio={setAspectRatio}
              showWorldGrid={envSettings.showWorldGrid}
              onToggleWorldGrid={() => setEnvSettings((s) => ({ ...s, showWorldGrid: !s.showWorldGrid }))}
              isAutoRotate={isAutoRotate}
              onToggleAutoRotate={() => setIsAutoRotate(!isAutoRotate)}
              outlinerItemCount={outlinerItems.length}
              isMeasuring={isMeasuring}
              onToggleMeasure={() => {
                setIsMeasuring((prev) => !prev);
                setMeasureStep(0);
                setCurrentMeasurement(null);
                measureStartPointRef.current = null;
              }}
              isAgentConsoleOpen={isAgentConsoleOpen}
              onToggleAgentConsole={() => setIsAgentConsoleOpen((prev) => !prev)}
            />
          </>
        ) : (
          <div className="w-full h-full flex flex-col items-center justify-center p-6 bg-slate-950 overflow-hidden relative">
            {serverSnapshotUrl ? (
              <div className="relative max-w-4xl max-h-[85%] rounded-2xl overflow-hidden border border-white/20 shadow-2xl group">
                <img
                  src={serverSnapshotUrl}
                  alt="3D Building Snapshot"
                  className="w-full h-full object-contain rounded-2xl"
                />
                <div className="absolute bottom-3 left-3 px-3 py-1.5 rounded-xl bg-black/70 backdrop-blur-md text-[11px] font-mono text-sky-400 border border-white/10">
                  Engine: @onirenaud/node-webgl (ANGLE ES3) • 1280x720 HD
                </div>
              </div>
            ) : (
              <div className="text-center p-8 max-w-md">
                <Server className="w-12 h-12 text-slate-500 mx-auto mb-3 animate-pulse" />
                <h4 className="text-sm font-bold text-white">Chưa có ảnh kết xuất từ Server</h4>
                <button
                  type="button"
                  onClick={handleTriggerServerRender}
                  disabled={isRenderingServer}
                  className="mt-4 px-4 py-2 rounded-xl bg-sky-600 text-xs font-semibold text-white shadow-lg cursor-pointer"
                >
                  {isRenderingServer ? 'Đang render...' : 'Chạy Render GPU Ngay'}
                </button>
              </div>
            )}
          </div>
        )}

        {/* Thông báo tiến trình render */}
        {serverRenderMsg && (
          <div className="absolute top-28 left-1/2 -translate-x-1/2 z-30 px-4 py-2 rounded-2xl bg-slate-900/95 text-sky-300 text-xs font-medium border border-sky-500/30 shadow-2xl backdrop-blur-xl animate-in fade-in slide-in-from-top-2">
            {serverRenderMsg}
          </div>
        )}

        {/* ================= 5. BẢNG THANH CÔNG CỤ DƯỚI (BOTTOM CONTROLS) ================= */}
        {viewMode === 'interactive' && (
          <div className="absolute bottom-4 left-4 z-20 hidden md:flex flex-wrap items-center gap-2 p-1.5 rounded-2xl bg-slate-900/90 backdrop-blur-xl border border-white/10 shadow-2xl">
            {/* Ánh sáng Ngày / Hoàng hôn / Đêm */}
            <div className="flex items-center gap-1 p-0.5 rounded-xl bg-white/5 border border-white/5">
              <button
                type="button"
                onClick={() => setTimeMode('day')}
                className={`p-2 rounded-lg text-xs transition-all cursor-pointer ${
                  timeMode === 'day' ? 'bg-amber-500 text-slate-950 font-bold shadow' : 'text-slate-400 hover:text-white'
                }`}
                title="Ban ngày (Ánh nắng rực rỡ)"
              >
                <Sun className="w-4 h-4" />
              </button>
              <button
                type="button"
                onClick={() => setTimeMode('sunset')}
                className={`p-2 rounded-lg text-xs transition-all cursor-pointer ${
                  timeMode === 'sunset' ? 'bg-rose-500 text-white font-bold shadow' : 'text-slate-400 hover:text-white'
                }`}
                title="Hoàng hôn (Ánh vàng cam ấm)"
              >
                <Sunset className="w-4 h-4" />
              </button>
              <button
                type="button"
                onClick={() => setTimeMode('night')}
                className={`p-2 rounded-lg text-xs transition-all cursor-pointer ${
                  timeMode === 'night' ? 'bg-indigo-600 text-white font-bold shadow' : 'text-slate-400 hover:text-white'
                }`}
                title="Ban đêm (Đèn LED & Cửa sổ sáng)"
              >
                <Moon className="w-4 h-4" />
              </button>
            </div>

            <button
              type="button"
              onClick={() => setIsXRayMode(!isXRayMode)}
              className={`p-2.5 rounded-xl text-xs font-medium transition-all cursor-pointer ${
                isXRayMode ? 'bg-sky-600 text-white shadow-md' : 'text-slate-400 hover:text-white'
              }`}
              title="Chế độ X-Ray (Xuyên thấu cấu trúc lõi thang)"
            >
              <Layers className="w-4 h-4" />
            </button>

            <button
              type="button"
              onClick={() => setShowUrbanContext(!showUrbanContext)}
              className={`p-2.5 rounded-xl text-xs font-medium transition-all cursor-pointer ${
                showUrbanContext ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-400 hover:text-white'
              }`}
              title="Bật/Tắt bối cảnh đô thị"
            >
              <TreePine className="w-4 h-4" />
            </button>

            <button
              type="button"
              onClick={() => setShowPins(!showPins)}
              className={`p-2.5 rounded-xl text-xs font-medium transition-all cursor-pointer ${
                showPins ? 'bg-emerald-600 text-white shadow-md' : 'text-slate-400 hover:text-white'
              }`}
              title="Bật/Tắt 14 ghim chú thích bản vẽ quy hoạch"
            >
              <MapPin className="w-4 h-4" />
            </button>

            <button
              type="button"
              onClick={() => setIsAutoRotate(!isAutoRotate)}
              className={`p-2.5 rounded-xl text-xs font-medium transition-all cursor-pointer ${
                isAutoRotate ? 'bg-sky-600 text-white shadow-md' : 'text-slate-400 hover:text-white'
              }`}
              title="Tự động xoay 360°"
            >
              <RotateCw className="w-4 h-4" />
            </button>

            <button
              type="button"
              onClick={handleClientSnapshot}
              className="p-2.5 rounded-xl text-xs font-medium text-slate-400 hover:text-white transition-all cursor-pointer"
              title="Chụp ảnh snapshot nhanh"
            >
              <Camera className="w-4 h-4 text-sky-400" />
            </button>
          </div>
        )}

        {/* ================= 6. BẢNG THANH THÔNG TIN BÊN PHẢI (PROFESSIONAL BIM INSPECTOR) ================= */}
        {activeTab === 'overview' && selectedBlock && (
          <div className="absolute top-28 right-4 bottom-16 z-20 w-88 max-w-[calc(100vw-2rem)] flex flex-col rounded-3xl bg-slate-900/95 backdrop-blur-2xl border border-white/15 p-4 shadow-2xl animate-in fade-in slide-in-from-right-3 overflow-hidden">
            {/* Header phân khu */}
            <div className="flex items-center justify-between pb-3 border-b border-white/10 shrink-0">
              <div>
                <span className="text-[10px] font-bold text-sky-400 uppercase font-mono tracking-wider">
                  {selectedBlock.code} • ĐẠT CHUẨN TCVN 06:2022
                </span>
                <h4 className="text-sm font-black text-white">{selectedBlock.name}</h4>
              </div>
              <span className="px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                {selectedBlock.status}
              </span>
            </div>

            {/* Chuyển tab trong Inspector */}
            <div className="flex items-center gap-1 mt-3 p-1 rounded-xl bg-white/5 border border-white/5 shrink-0 text-xs">
              <button
                type="button"
                onClick={() => setActiveInspectorTab('space')}
                className={`flex-1 py-1.5 rounded-lg font-bold transition-all text-center cursor-pointer ${
                  activeInspectorTab === 'space' ? 'bg-sky-600 text-white shadow' : 'text-slate-400 hover:text-white'
                }`}
              >
                Không Gian
              </button>
              <button
                type="button"
                onClick={() => setActiveInspectorTab('mep')}
                className={`flex-1 py-1.5 rounded-lg font-bold transition-all text-center cursor-pointer ${
                  activeInspectorTab === 'mep' ? 'bg-indigo-600 text-white shadow' : 'text-slate-400 hover:text-white'
                }`}
              >
                Kỹ Thuật MEP
              </button>
              <button
                type="button"
                onClick={() => setActiveInspectorTab('materials')}
                className={`flex-1 py-1.5 rounded-lg font-bold transition-all text-center cursor-pointer ${
                  activeInspectorTab === 'materials' ? 'bg-teal-600 text-white shadow' : 'text-slate-400 hover:text-white'
                }`}
              >
                Vật Liệu
              </button>
            </div>

            {/* Nội dung Tab */}
            <div className="flex-1 overflow-y-auto mt-3 pr-1 space-y-3 text-xs">
              {activeInspectorTab === 'space' && (
                <>
                  <p className="text-[11px] text-slate-300 leading-relaxed bg-white/5 p-2.5 rounded-xl border border-white/5">
                    {selectedBlock.description}
                  </p>

                  <div className="grid grid-cols-2 gap-2">
                    <div className="p-2.5 rounded-xl bg-white/5 border border-white/5">
                      <span className="text-[10px] text-slate-400">Số tầng cao</span>
                      <div className="text-base font-black text-white mt-0.5 font-mono">
                        {selectedBlock.floors} <span className="text-xs font-normal text-slate-400">tầng</span>
                      </div>
                    </div>
                    <div className="p-2.5 rounded-xl bg-white/5 border border-white/5">
                      <span className="text-[10px] text-slate-400">Tổng căn / Phòng</span>
                      <div className="text-base font-black text-white mt-0.5 font-mono">
                        {selectedBlock.apartments} <span className="text-xs font-normal text-slate-400">căn</span>
                      </div>
                    </div>
                    <div className="p-2.5 rounded-xl bg-white/5 border border-white/5">
                      <span className="text-[10px] text-slate-400">Dân cư / Khách</span>
                      <div className="text-base font-black text-white mt-0.5 font-mono">
                        {selectedBlock.residents} <span className="text-xs font-normal text-slate-400">người</span>
                      </div>
                    </div>
                    <div className="p-2.5 rounded-xl bg-white/5 border border-white/5">
                      <span className="text-[10px] text-slate-400">Tỷ lệ lấp đầy</span>
                      <div className="text-base font-black text-emerald-400 mt-0.5 font-mono">
                        {selectedBlock.occupancyRate}%
                      </div>
                    </div>
                  </div>
                </>
              )}

              {activeInspectorTab === 'mep' && (
                <div className="space-y-2">
                  <div className="p-2.5 rounded-xl bg-white/5 border border-white/5 flex items-start gap-2.5">
                    <ShieldCheck className="w-4 h-4 text-emerald-400 shrink-0 mt-0.5" />
                    <div>
                      <div className="font-bold text-white text-[11px]">PCCC & Hút Khói Tự Động</div>
                      <div className="text-[10px] text-slate-400 leading-snug">
                        Chuẩn TCVN 06:2022, đầu phun Sprinkler phản ứng nhanh, cửa chống cháy EI 60, thang thoát hiểm áp lực dương.
                      </div>
                    </div>
                  </div>

                  <div className="p-2.5 rounded-xl bg-white/5 border border-white/5 flex items-start gap-2.5">
                    <Zap className="w-4 h-4 text-amber-400 shrink-0 mt-0.5" />
                    <div>
                      <div className="font-bold text-white text-[11px]">Hệ Thống Điện & Trạm Sạc EV</div>
                      <div className="text-[10px] text-slate-400 leading-snug">
                        2 Trạm biến áp 2x2000kVA, máy phát Cummins dự phòng 100%, 24 trạm sạc nhanh xe điện 120kW tầng hầm B1-B2.
                      </div>
                    </div>
                  </div>

                  <div className="p-2.5 rounded-xl bg-white/5 border border-white/5 flex items-start gap-2.5">
                    <Wind className="w-4 h-4 text-cyan-400 shrink-0 mt-0.5" />
                    <div>
                      <div className="font-bold text-white text-[11px]">Điều Hòa Chiller & VRV</div>
                      <div className="text-[10px] text-slate-400 leading-snug">
                        Hệ thống điều hòa trung tâm làm mát bằng nước Daikin biến tần, lọc không khí ion âm tươi từng căn hộ.
                      </div>
                    </div>
                  </div>

                  <div className="p-2.5 rounded-xl bg-white/5 border border-white/5 flex items-start gap-2.5">
                    <Activity className="w-4 h-4 text-purple-400 shrink-0 mt-0.5" />
                    <div>
                      <div className="font-bold text-white text-[11px]">Thang Máy Cao Tốc 4.0 m/s</div>
                      <div className="text-[10px] text-slate-400 leading-snug">
                        3 Thang Mitsubishi tốc độ cao tại lõi tam giác + 1 thang máy kính ngoài trời ngắm vịnh biển.
                      </div>
                    </div>
                  </div>

                  <div className="p-2.5 rounded-xl bg-white/5 border border-white/5 flex items-start gap-2.5">
                    <Droplets className="w-4 h-4 text-blue-400 shrink-0 mt-0.5" />
                    <div>
                      <div className="font-bold text-white text-[11px]">Cấp Thoát Nước & Bể PCCC 800m³</div>
                      <div className="text-[10px] text-slate-400 leading-snug">
                        Hệ thống lọc nước uống tại vòi tiêu chuẩn Châu Âu, bể ngầm điều tiết chống ngập tầng hầm.
                      </div>
                    </div>
                  </div>
                </div>
              )}

              {activeInspectorTab === 'materials' && (
                <div className="space-y-2">
                  <div className="p-2.5 rounded-xl bg-white/5 border border-white/5">
                    <div className="font-bold text-white text-[11px]">Kính Hộp Low-E Double Silver (24mm)</div>
                    <div className="text-[10px] text-slate-400 leading-snug mt-1">
                      Cản nhiệt 85%, giảm bức xạ UV 99%, cách âm 42dB, tối ưu hóa năng lượng làm mát.
                    </div>
                  </div>

                  <div className="p-2.5 rounded-xl bg-white/5 border border-white/5">
                    <div className="font-bold text-white text-[11px]">Lam Chắn Nắng Nhôm Định Hình Anodized</div>
                    <div className="text-[10px] text-slate-400 leading-snug mt-1">
                      Chống ăn mòn muối biển Đà Nẵng, tạo hình facade nếp gấp tham số (Sheet 9).
                    </div>
                  </div>

                  <div className="p-2.5 rounded-xl bg-white/5 border border-white/5">
                    <div className="font-bold text-white text-[11px]">Sàn Gỗ Teak Tự Nhiên Kháng Nước Biển</div>
                    <div className="text-[10px] text-slate-400 leading-snug mt-1">
                      Lát quanh bể bơi vô cực tầng 5 và cầu đi bộ cảnh quan vượt hồ sinh thái.
                    </div>
                  </div>

                  <div className="p-2.5 rounded-xl bg-white/5 border border-white/5">
                    <div className="font-bold text-white text-[11px]">Đá Marble Tự Nhiên & Nẹp Vàng PVD</div>
                    <div className="text-[10px] text-slate-400 leading-snug mt-1">
                      Ốp vách trang trí đại sảnh và căn hộ VIP Suite chuẩn khách sạn 5 sao quốc tế.
                    </div>
                  </div>
                </div>
              )}
            </div>

            {/* IoT Telemetry Realtime */}
            <div className="mt-3 pt-3 border-t border-white/10 flex items-center justify-between text-[11px] text-slate-400 shrink-0">
              <div className="flex items-center gap-1 text-amber-400 font-mono">
                <Zap className="w-3.5 h-3.5" />
                <span>{selectedBlock.powerKw} kW</span>
              </div>
              <div className="flex items-center gap-1 text-rose-400 font-mono">
                <Thermometer className="w-3.5 h-3.5" />
                <span>{selectedBlock.temp}°C</span>
              </div>
              <div className="flex items-center gap-1 text-emerald-400 font-bold">
                <CheckCircle2 className="w-3.5 h-3.5" />
                <span>MEP Active</span>
              </div>
            </div>
          </div>
        )}

        {/* ================= 7. THẺ CĂN HỘ VIP SUITE (Sheet 9) ================= */}
        {activeTab === 'vip_suite' && (
          <div className="absolute top-28 right-4 z-20 w-88 rounded-3xl bg-slate-900/95 backdrop-blur-2xl border border-amber-500/30 p-4 shadow-2xl animate-in fade-in slide-in-from-top-2">
            <div className="flex items-center justify-between pb-2.5 border-b border-white/10">
              <span className="px-2.5 py-0.5 rounded-lg text-xs font-black bg-amber-500/20 text-amber-400 border border-amber-500/30 font-mono">
                SHEET 9 VIP SUITE
              </span>
              <span className="text-[11px] font-bold text-emerald-400 font-mono">118 m² Luxury</span>
            </div>

            <h4 className="text-sm font-black text-white mt-2">Nội Thất Phòng Khách Căn Hộ VIP</h4>
            <p className="text-[11px] text-slate-300 mt-1 leading-relaxed">
              Thiết kế bám sát bản vẽ Sheet 9: Vách đá Marble xám nẹp PVD vàng, sofa chữ L, đôn Houndstooth, ghế chú cún Magis Puppy và ban công hướng biển.
            </p>

            <div className="mt-3 space-y-2 max-h-72 overflow-y-auto pr-1">
              {VIP_SUITE_HOTSPOTS.map((hs) => (
                <button
                  key={hs.id}
                  type="button"
                  onClick={() => setSelectedHotspot(hs)}
                  className={`w-full text-left p-2.5 rounded-xl transition-all flex items-start gap-2.5 text-xs cursor-pointer ${
                    selectedHotspot?.id === hs.id
                      ? 'bg-amber-500/20 border border-amber-500/40 text-amber-200'
                      : 'bg-white/5 border border-white/5 text-slate-300 hover:bg-white/10'
                  }`}
                >
                  <Sparkles className="w-4 h-4 text-amber-400 shrink-0 mt-0.5" />
                  <div>
                    <div className="font-bold text-white text-[11px]">{hs.title}</div>
                    <div className="text-[10px] text-slate-400 leading-snug mt-0.5">{hs.desc}</div>
                  </div>
                </button>
              ))}
            </div>
          </div>
        )}

        {/* ================= 8. THẺ MẶT BẰNG TẦNG ĐIỂN HÌNH (Sheet 10) ================= */}
        {activeTab === 'typical_plate' && (
          <div className="absolute top-28 right-4 z-20 w-88 rounded-3xl bg-slate-900/95 backdrop-blur-2xl border border-teal-500/30 p-4 shadow-2xl animate-in fade-in slide-in-from-top-2">
            <div className="flex items-center justify-between pb-2.5 border-b border-white/10">
              <span className="px-2.5 py-0.5 rounded-lg text-xs font-black bg-teal-500/20 text-teal-400 border border-teal-500/30 font-mono">
                SHEET 10 TYPICAL
              </span>
              <span className="text-[11px] font-bold text-sky-400 font-mono">1.160 m²/sàn</span>
            </div>

            <h4 className="text-sm font-black text-white mt-2">Mặt Bằng Tầng Điển Hình Khách Sạn & Văn Phòng</h4>
            <p className="text-[11px] text-slate-300 mt-1 leading-relaxed">
              Mặt bằng tam giác bo 3 góc mềm mại khí động học. Lõi dịch vụ tam giác ở trung tâm với 3 buồng thang máy cam, thang bộ thoát hiểm và hộp kỹ thuật MEP.
            </p>

            <div className="mt-3 space-y-2 text-xs">
              <div className="p-2.5 rounded-xl bg-white/5 border border-white/5 flex items-center justify-between">
                <span className="text-slate-300 font-semibold">3 Căn Hộ VIP Góc (Số 3)</span>
                <span className="text-sky-400 font-mono font-bold">118 m² • View Biển 180°</span>
              </div>
              <div className="p-2.5 rounded-xl bg-white/5 border border-white/5 flex items-center justify-between">
                <span className="text-slate-300 font-semibold">Lõi Dịch Vụ & 3 Thang Máy</span>
                <span className="text-amber-400 font-mono font-bold">4.0 m/s High Speed</span>
              </div>
              <div className="p-2.5 rounded-xl bg-white/5 border border-white/5 flex items-center justify-between">
                <span className="text-slate-300 font-semibold">Phòng Tiêu Chuẩn 1 & 2 Giường</span>
                <span className="text-emerald-400 font-mono font-bold">45 - 65 m²</span>
              </div>
            </div>

            <button
              type="button"
              onClick={() => setActiveTab('vip_suite')}
              className="mt-4 w-full py-2.5 px-3 rounded-xl bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-500 hover:to-orange-500 text-white text-xs font-bold shadow-lg transition-all flex items-center justify-center gap-1.5 cursor-pointer"
            >
              <Armchair className="w-4 h-4" />
              <span>Khám Phá Không Gian 3D Căn Hộ VIP</span>
            </button>
          </div>
        )}

        {/* ================= 9. THẺ CỐT TẦNG BIM ================= */}
        {activeTab === 'floors' && selectedFloor && (
          <div className="absolute top-28 right-4 z-20 w-84 rounded-3xl bg-slate-900/95 backdrop-blur-2xl border border-sky-500/30 p-4 shadow-2xl animate-in fade-in slide-in-from-top-2">
            <div className="flex items-center justify-between pb-2 border-b border-white/10">
              <span className="px-2.5 py-0.5 rounded-lg text-xs font-black bg-sky-500/20 text-sky-400 border border-sky-500/30 font-mono">
                {selectedFloor.level}
              </span>
              <span className="text-[11px] font-bold text-emerald-400 font-mono">
                {selectedFloor.areaM2.toLocaleString()} m²
              </span>
            </div>

            <h4 className="text-sm font-black text-white mt-2">{selectedFloor.name}</h4>
            <div className="text-[11px] text-slate-400 font-mono mt-0.5">Cao độ thiết kế: {selectedFloor.elevation}</div>

            <div className="mt-3 space-y-1.5">
              <span className="text-[10px] text-slate-400 uppercase font-mono tracking-wider">
                Phân bổ công năng theo bản vẽ:
              </span>
              {selectedFloor.functions.map((fn, idx) => (
                <div key={idx} className="flex items-center gap-2 text-xs text-slate-200">
                  <span className="w-1.5 h-1.5 rounded-full bg-sky-400 shrink-0" />
                  <span>{fn}</span>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </div>
  );
};
