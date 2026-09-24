import React, { useState, useMemo } from 'react';
import {
  Building2,
  Building,
  Home,
  CheckCircle2,
  AlertTriangle,
  Wrench,
  Search,
  Filter,
  ShieldCheck,
  Zap,
  Droplet,
  Layers,
  ArrowUpRight,
  Users,
  ChevronRight,
  Eye,
  SlidersHorizontal,
} from 'lucide-react';

interface BuildingInfo {
  id: string;
  code: string;
  name: string;
  floors: number;
  basements: number;
  elevators: number;
  totalUnits: number;
  occupiedUnits: number;
  vacantUnits: number;
  renovatingUnits: number;
  manager: string;
  managerPhone: string;
  fireSafetyStatus: 'safe' | 'warning';
  powerStatus: 'stable' | 'alert';
  waterStatus: 'stable' | 'alert';
}

interface ApartmentUnit {
  id: string;
  unitCode: string;
  buildingCode: string;
  floor: number;
  type: string;
  area: number;
  ownerName: string;
  ownerPhone: string;
  status: 'occupied' | 'vacant' | 'renovating';
  feeStatus: 'paid' | 'unpaid';
}

const BUILDINGS_DATA: BuildingInfo[] = [
  {
    id: 'b1',
    code: 'S1',
    name: 'Tháp S1 - Sky Tower',
    floors: 26,
    basements: 2,
    elevators: 6,
    totalUnits: 260,
    occupiedUnits: 242,
    vacantUnits: 12,
    renovatingUnits: 6,
    manager: 'Trần Đình Trọng',
    managerPhone: '0901.888.111',
    fireSafetyStatus: 'safe',
    powerStatus: 'stable',
    waterStatus: 'stable',
  },
  {
    id: 'b2',
    code: 'S2',
    name: 'Tháp S2 - Aqua Tower',
    floors: 26,
    basements: 2,
    elevators: 6,
    totalUnits: 240,
    occupiedUnits: 218,
    vacantUnits: 15,
    renovatingUnits: 7,
    manager: 'Lê Hoàng Nam',
    managerPhone: '0901.888.222',
    fireSafetyStatus: 'safe',
    powerStatus: 'stable',
    waterStatus: 'stable',
  },
  {
    id: 'b3',
    code: 'S3',
    name: 'Tháp S3 - Garden Tower',
    floors: 22,
    basements: 2,
    elevators: 4,
    totalUnits: 180,
    occupiedUnits: 160,
    vacantUnits: 15,
    renovatingUnits: 5,
    manager: 'Vũ Minh Tuấn',
    managerPhone: '0901.888.333',
    fireSafetyStatus: 'safe',
    powerStatus: 'stable',
    waterStatus: 'stable',
  },
];

const SAMPLE_APARTMENTS: ApartmentUnit[] = [
  { id: 'u1', unitCode: 'S1.2501', buildingCode: 'S1', floor: 25, type: 'Penthouse Duplex', area: 185.5, ownerName: 'Nguyễn Văn An', ownerPhone: '0912.345.678', status: 'occupied', feeStatus: 'paid' },
  { id: 'u2', unitCode: 'S1.2004', buildingCode: 'S1', floor: 20, type: '3 Phòng ngủ (Góc)', area: 115.0, ownerName: 'Trần Thị Mai', ownerPhone: '0988.234.567', status: 'occupied', feeStatus: 'paid' },
  { id: 'u3', unitCode: 'S1.1802', buildingCode: 'S1', floor: 18, type: '2 Phòng ngủ', area: 78.5, ownerName: 'Lê Quang Huy', ownerPhone: '0903.112.334', status: 'occupied', feeStatus: 'unpaid' },
  { id: 'u4', unitCode: 'S1.1408', buildingCode: 'S1', floor: 14, type: '2 Phòng ngủ', area: 74.0, ownerName: 'Đang bàn giao chủ đầu tư', ownerPhone: '---', status: 'vacant', feeStatus: 'paid' },
  { id: 'u5', unitCode: 'S1.0805', buildingCode: 'S1', floor: 8, type: '2 Phòng ngủ', area: 78.5, ownerName: 'Phạm Đức Thắng', ownerPhone: '0934.556.778', status: 'renovating', feeStatus: 'paid' },
  { id: 'u6', unitCode: 'S2.2201', buildingCode: 'S2', floor: 22, type: '3 Phòng ngủ', area: 110.0, ownerName: 'Hoàng Minh Châu', ownerPhone: '0915.667.889', status: 'occupied', feeStatus: 'paid' },
  { id: 'u7', unitCode: 'S2.1603', buildingCode: 'S2', floor: 16, type: '2 Phòng ngủ', area: 82.0, ownerName: 'Đặng Quốc Toàn', ownerPhone: '0977.889.900', status: 'occupied', feeStatus: 'unpaid' },
  { id: 'u8', unitCode: 'S2.0502', buildingCode: 'S2', floor: 5, type: '1 Phòng ngủ + 1', area: 56.5, ownerName: 'Trống chờ thuê', ownerPhone: '---', status: 'vacant', feeStatus: 'paid' },
  { id: 'u9', unitCode: 'S3.1201', buildingCode: 'S3', floor: 12, type: '2 Phòng ngủ', area: 76.0, ownerName: 'Bùi Thanh Hương', ownerPhone: '0908.445.566', status: 'occupied', feeStatus: 'paid' },
  { id: 'u10', unitCode: 'S3.0706', buildingCode: 'S3', floor: 7, type: '2 Phòng ngủ', area: 76.0, ownerName: 'Ngô Kiến Huy', ownerPhone: '0922.334.455', status: 'renovating', feeStatus: 'paid' },
];

export const BuildingListManagement: React.FC = () => {
  const [selectedBuildingFilter, setSelectedBuildingFilter] = useState<string>('all');
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [statusFilter, setStatusFilter] = useState<string>('all');

  const totalUnitsAll = useMemo(() => BUILDINGS_DATA.reduce((sum, b) => sum + b.totalUnits, 0), []);
  const occupiedUnitsAll = useMemo(() => BUILDINGS_DATA.reduce((sum, b) => sum + b.occupiedUnits, 0), []);
  const vacantUnitsAll = useMemo(() => BUILDINGS_DATA.reduce((sum, b) => sum + b.vacantUnits, 0), []);
  const renovatingUnitsAll = useMemo(() => BUILDINGS_DATA.reduce((sum, b) => sum + b.renovatingUnits, 0), []);
  const overallOccupancyRate = ((occupiedUnitsAll / totalUnitsAll) * 100).toFixed(1);

  const filteredBuildings = useMemo(() => {
    if (selectedBuildingFilter === 'all') return BUILDINGS_DATA;
    return BUILDINGS_DATA.filter((b) => b.code === selectedBuildingFilter);
  }, [selectedBuildingFilter]);

  const filteredApartments = useMemo(() => {
    return SAMPLE_APARTMENTS.filter((apt) => {
      const matchBuilding = selectedBuildingFilter === 'all' || apt.buildingCode === selectedBuildingFilter;
      const matchStatus = statusFilter === 'all' || apt.status === statusFilter;
      const matchSearch =
        searchQuery.trim() === '' ||
        apt.unitCode.toLowerCase().includes(searchQuery.toLowerCase()) ||
        apt.ownerName.toLowerCase().includes(searchQuery.toLowerCase()) ||
        apt.type.toLowerCase().includes(searchQuery.toLowerCase());
      return matchBuilding && matchStatus && matchSearch;
    });
  }, [selectedBuildingFilter, statusFilter, searchQuery]);

  return (
    <div className="w-full max-w-[2000px] mx-auto px-4 sm:px-6 lg:px-8 xl:px-10 py-6 sm:py-8 space-y-6 transition-all duration-300">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-slate-200/80">
        <div>
          <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider font-mono text-sky-600">
            <Building2 className="w-4 h-4 text-sky-500" />
            <span>HỆ THỐNG KHỐI TÒA NHÀ & CĂN HỘ</span>
            <span className="text-slate-300">·</span>
            <span className="text-slate-500">QUẢN LÝ DANH MỤC TRỰC QUAN 2D</span>
          </div>
          <h1 className="text-2xl sm:text-3xl font-extrabold text-neutral-900 tracking-tight mt-1">
            Khối / Tòa nhà & Căn hộ
          </h1>
          <p className="text-xs sm:text-sm text-slate-500 mt-1">
            Tổng quan hạ tầng các tòa tháp, danh sách căn hộ, hiện trạng cư trú và tình trạng kỹ thuật vận hành.
          </p>
        </div>

        {/* Building Selector Chips */}
        <div className="flex items-center gap-2 flex-wrap">
          <button
            type="button"
            onClick={() => setSelectedBuildingFilter('all')}
            className={`px-3 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer ${
              selectedBuildingFilter === 'all'
                ? 'bg-sky-600 text-white shadow-sm'
                : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200'
            }`}
          >
            Tất cả ({BUILDINGS_DATA.length} Tòa)
          </button>
          {BUILDINGS_DATA.map((b) => (
            <button
              key={b.id}
              type="button"
              onClick={() => setSelectedBuildingFilter(b.code)}
              className={`px-3 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer ${
                selectedBuildingFilter === b.code
                  ? 'bg-sky-600 text-white shadow-sm'
                  : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200'
              }`}
            >
              {b.name}
            </button>
          ))}
        </div>
      </div>

      {/* 4 Summary Stats Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* Card 1: Tổng căn hộ */}
        <div className="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-500">Tổng quy mô căn hộ</span>
            <div className="w-8 h-8 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center font-bold">
              <Building className="w-4 h-4" />
            </div>
          </div>
          <div className="mt-2 text-2xl font-extrabold text-neutral-900 font-mono">
            {totalUnitsAll} <span className="text-xs font-normal text-slate-400">căn</span>
          </div>
          <div className="mt-2 text-xs text-slate-500">
            Phân bổ tại 3 tháp cao 22 - 26 tầng
          </div>
        </div>

        {/* Card 2: Đang ở (Tỷ lệ lấp đầy) */}
        <div className="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-500">Căn hộ đang cư trú</span>
            <div className="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
              <CheckCircle2 className="w-4 h-4" />
            </div>
          </div>
          <div className="mt-2 text-2xl font-extrabold text-emerald-600 font-mono">
            {occupiedUnitsAll} <span className="text-xs font-normal text-slate-400">({overallOccupancyRate}%)</span>
          </div>
          <div className="mt-2 text-xs text-emerald-700 font-medium">
            Tỷ lệ lấp đầy đạt chỉ tiêu vận hành
          </div>
        </div>

        {/* Card 3: Căn hộ trống */}
        <div className="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-500">Căn hộ trống / Chờ bàn giao</span>
            <div className="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
              <Home className="w-4 h-4" />
            </div>
          </div>
          <div className="mt-2 text-2xl font-extrabold text-amber-600 font-mono">
            {vacantUnitsAll} <span className="text-xs font-normal text-slate-400">căn</span>
          </div>
          <div className="mt-2 text-xs text-amber-700 font-medium">
            Sẵn sàng bàn giao hoặc cho thuê
          </div>
        </div>

        {/* Card 4: Đang thi công sửa chữa */}
        <div className="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-500">Đang thi công nội thất</span>
            <div className="w-8 h-8 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center">
              <Wrench className="w-4 h-4" />
            </div>
          </div>
          <div className="mt-2 text-2xl font-extrabold text-purple-600 font-mono">
            {renovatingUnitsAll} <span className="text-xs font-normal text-slate-400">căn</span>
          </div>
          <div className="mt-2 text-xs text-purple-700 font-medium">
            Có giấy phép sửa chữa hợp lệ
          </div>
        </div>
      </div>

      {/* Buildings Detailed Cards */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-5">
        {filteredBuildings.map((building) => {
          const occupancyRate = ((building.occupiedUnits / building.totalUnits) * 100).toFixed(1);
          return (
            <div
              key={building.id}
              className="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-xs hover:shadow-md transition-all flex flex-col justify-between"
            >
              <div>
                <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                  <div className="flex items-center gap-2.5">
                    <div className="w-9 h-9 rounded-xl bg-sky-100 text-sky-700 flex items-center justify-center font-bold text-sm">
                      {building.code}
                    </div>
                    <div>
                      <h3 className="font-bold text-slate-900 text-sm">{building.name}</h3>
                      <p className="text-[11px] text-slate-500">{building.floors} Tầng nổi · {building.basements} Tầng hầm · {building.elevators} Thang máy</p>
                    </div>
                  </div>
                  <span className="text-xs font-bold px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-700 border border-emerald-200">
                    Online
                  </span>
                </div>

                {/* Progress bar */}
                <div className="mt-4">
                  <div className="flex justify-between text-xs text-slate-600 font-medium mb-1">
                    <span>Lấp đầy: {building.occupiedUnits}/{building.totalUnits} căn</span>
                    <span className="font-bold text-neutral-900">{occupancyRate}%</span>
                  </div>
                  <div className="w-full h-2 bg-slate-100 rounded-full overflow-hidden">
                    <div
                      className="h-full bg-sky-600 rounded-full transition-all duration-500"
                      style={{ width: `${occupancyRate}%` }}
                    />
                  </div>
                </div>

                {/* Technical Systems status */}
                <div className="mt-4 grid grid-cols-3 gap-2 text-center text-xs">
                  <div className="p-2 rounded-xl bg-slate-50 border border-slate-100">
                    <div className="flex items-center justify-center gap-1 text-emerald-600 font-medium text-[11px]">
                      <ShieldCheck className="w-3.5 h-3.5" /> PCCC
                    </div>
                    <span className="text-[10px] text-slate-500 font-semibold">Chuẩn 100%</span>
                  </div>
                  <div className="p-2 rounded-xl bg-slate-50 border border-slate-100">
                    <div className="flex items-center justify-center gap-1 text-amber-600 font-medium text-[11px]">
                      <Zap className="w-3.5 h-3.5" /> Điện
                    </div>
                    <span className="text-[10px] text-slate-500 font-semibold">Ổn định</span>
                  </div>
                  <div className="p-2 rounded-xl bg-slate-50 border border-slate-100">
                    <div className="flex items-center justify-center gap-1 text-sky-600 font-medium text-[11px]">
                      <Droplet className="w-3.5 h-3.5" /> Nước
                    </div>
                    <span className="text-[10px] text-slate-500 font-semibold">Áp lực tốt</span>
                  </div>
                </div>
              </div>

              <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
                <span>Trưởng ca: <strong className="text-slate-800">{building.manager}</strong></span>
                <span className="font-mono text-sky-600">{building.managerPhone}</span>
              </div>
            </div>
          );
        })}
      </div>

      {/* Apartment Units Table Section */}
      <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        {/* Search & Filter Toolbar */}
        <div className="p-4 border-b border-slate-100 flex flex-col sm:flex-row gap-3 items-center justify-between bg-slate-50/50">
          <div className="flex items-center gap-2 w-full sm:w-auto">
            <div className="relative w-full sm:w-72">
              <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Tìm mã căn (vd: S1.2501), chủ hộ..."
                className="w-full pl-9 pr-3 py-1.5 text-xs bg-white border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500"
              />
            </div>

            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="px-3 py-1.5 text-xs bg-white border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/20 text-slate-700 font-medium cursor-pointer"
            >
              <option value="all">Mọi trạng thái</option>
              <option value="occupied">Đang ở</option>
              <option value="vacant">Trống</option>
              <option value="renovating">Đang sửa chữa</option>
            </select>
          </div>

          <div className="text-xs text-slate-500 font-medium self-end sm:self-auto">
            Hiển thị <strong>{filteredApartments.length}</strong> căn hộ
          </div>
        </div>

        {/* Table */}
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="bg-slate-50 border-b border-slate-100 text-slate-500 uppercase tracking-wider font-semibold">
              <tr>
                <th className="py-3 px-4">Mã Căn hộ</th>
                <th className="py-3 px-4">Tòa / Tầng</th>
                <th className="py-3 px-4">Loại Căn</th>
                <th className="py-3 px-4">Diện tích</th>
                <th className="py-3 px-4">Chủ hộ / Đại diện</th>
                <th className="py-3 px-4">Số điện thoại</th>
                <th className="py-3 px-4">Trạng thái cư trú</th>
                <th className="py-3 px-4">Phí dịch vụ</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 text-slate-700">
              {filteredApartments.length === 0 ? (
                <tr>
                  <td colSpan={8} className="py-8 text-center text-slate-400">
                    Không tìm thấy căn hộ nào phù hợp với bộ lọc tìm kiếm.
                  </td>
                </tr>
              ) : (
                filteredApartments.map((apt) => (
                  <tr key={apt.id} className="hover:bg-slate-50/80 transition-colors">
                    <td className="py-3 px-4 font-bold text-neutral-900 font-mono">
                      {apt.unitCode}
                    </td>
                    <td className="py-3 px-4">
                      Tháp {apt.buildingCode} · Tầng {apt.floor}
                    </td>
                    <td className="py-3 px-4 font-medium text-slate-600">
                      {apt.type}
                    </td>
                    <td className="py-3 px-4 font-mono">
                      {apt.area} m²
                    </td>
                    <td className="py-3 px-4 font-semibold text-neutral-900">
                      {apt.ownerName}
                    </td>
                    <td className="py-3 px-4 font-mono text-slate-500">
                      {apt.ownerPhone}
                    </td>
                    <td className="py-3 px-4">
                      {apt.status === 'occupied' && (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                          <CheckCircle2 className="w-3 h-3" /> Đang ở
                        </span>
                      )}
                      {apt.status === 'vacant' && (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                          <Home className="w-3 h-3" /> Trống
                        </span>
                      )}
                      {apt.status === 'renovating' && (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-bold bg-purple-50 text-purple-700 border border-purple-200">
                          <Wrench className="w-3 h-3" /> Đang sửa chữa
                        </span>
                      )}
                    </td>
                    <td className="py-3 px-4">
                      {apt.feeStatus === 'paid' ? (
                        <span className="text-[11px] font-semibold text-emerald-600">Đã thanh toán</span>
                      ) : (
                        <span className="text-[11px] font-semibold text-rose-600">Chưa thanh toán</span>
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
};

export default BuildingListManagement;
