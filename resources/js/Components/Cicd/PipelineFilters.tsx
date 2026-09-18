import React, { useState, useEffect } from 'react';
import { Search, Filter, RotateCcw, GitBranch, Workflow, CheckCircle2, Clock } from 'lucide-react';
import { useDebounce } from '../../Hooks/useDebounce';

export interface FilterState {
  search: string;
  status: string;
  branch: string;
  workflow: string;
  dateRange: string;
}

interface PipelineFiltersProps {
  onFilterChange: (filters: FilterState) => void;
  availableBranches?: string[];
  totalResults: number;
}

export const PipelineFilters: React.FC<PipelineFiltersProps> = ({
  onFilterChange,
  availableBranches = ['all', 'main', 'master', 'DangNguyen/CI-CD', 'DangNguyen/amenity-management', 'feature/search'],
  totalResults,
}) => {
  const [search, setSearch] = useState<string>('');
  const [status, setStatus] = useState<string>('all');
  const [branch, setBranch] = useState<string>('all');
  const [workflow, setWorkflow] = useState<string>('all');
  const [dateRange, setDateRange] = useState<string>('all');

  // Debounce search query 300ms as required
  const debouncedSearch = useDebounce(search, 300);

  useEffect(() => {
    onFilterChange({
      search: debouncedSearch,
      status,
      branch,
      workflow,
      dateRange,
    });
  }, [debouncedSearch, status, branch, workflow, dateRange]);

  const handleReset = () => {
    setSearch('');
    setStatus('all');
    setBranch('all');
    setWorkflow('all');
    setDateRange('all');
  };

  const hasActiveFilters =
    search.trim() !== '' ||
    status !== 'all' ||
    branch !== 'all' ||
    workflow !== 'all' ||
    dateRange !== 'all';

  return (
    <div className="rounded-2xl bg-white/80 backdrop-blur-md p-4 border border-slate-200/80 shadow-xs space-y-3">
      <div className="flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-3">
        {/* Search Input with Debounce */}
        <div className="relative flex-1 min-w-[240px]">
          <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Tìm theo Commit SHA, Nhánh, Tác giả, Thông điệp..."
            className="w-full pl-10 pr-4 py-2 text-xs sm:text-sm rounded-xl bg-slate-50 hover:bg-slate-100/80 focus:bg-white border border-slate-200 focus:border-sky-500 focus:ring-2 focus:ring-sky-100 transition-all outline-none font-medium placeholder:text-slate-400"
          />
          {search && (
            <button
              type="button"
              onClick={() => setSearch('')}
              className="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400 hover:text-slate-600 font-bold"
            >
              &times;
            </button>
          )}
        </div>

        {/* Dropdown Filters */}
        <div className="flex flex-wrap items-center gap-2">
          {/* Status Filter */}
          <div className="flex items-center gap-1.5">
            <select
              value={status}
              onChange={(e) => setStatus(e.target.value)}
              className="text-xs font-semibold px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 hover:bg-slate-100/80 text-neutral-800 focus:outline-none focus:ring-2 focus:ring-sky-100 cursor-pointer"
            >
              <option value="all">Trạng thái: Tất cả</option>
              <option value="success">Thành công (Success)</option>
              <option value="failed">Thất bại (Failed)</option>
              <option value="running">Đang chạy (Running)</option>
              <option value="cancelled">Đã hủy (Cancelled)</option>
            </select>
          </div>

          {/* Branch Filter */}
          <div className="flex items-center gap-1.5">
            <select
              value={branch}
              onChange={(e) => setBranch(e.target.value)}
              className="text-xs font-semibold px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 hover:bg-slate-100/80 text-neutral-800 focus:outline-none focus:ring-2 focus:ring-sky-100 cursor-pointer"
            >
              <option value="all">Nhánh: Tất cả</option>
              {availableBranches
                .filter((b) => b !== 'all')
                .map((b) => (
                  <option key={b} value={b}>
                    {b}
                  </option>
                ))}
            </select>
          </div>

          {/* Workflow Filter */}
          <div className="flex items-center gap-1.5">
            <select
              value={workflow}
              onChange={(e) => setWorkflow(e.target.value)}
              className="text-xs font-semibold px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 hover:bg-slate-100/80 text-neutral-800 focus:outline-none focus:ring-2 focus:ring-sky-100 cursor-pointer"
            >
              <option value="all">Workflow: Tất cả</option>
              <option value="ci.yml">CI - Test & Build</option>
              <option value="docker.yml">Docker & GHCR</option>
              <option value="cd-staging.yml">CD Staging</option>
              <option value="cd-production.yml">CD Production</option>
            </select>
          </div>

          {/* Date Filter */}
          <div className="flex items-center gap-1.5">
            <select
              value={dateRange}
              onChange={(e) => setDateRange(e.target.value)}
              className="text-xs font-semibold px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 hover:bg-slate-100/80 text-neutral-800 focus:outline-none focus:ring-2 focus:ring-sky-100 cursor-pointer"
            >
              <option value="all">Thời gian: Tất cả</option>
              <option value="today">Hôm nay</option>
              <option value="7days">7 ngày qua</option>
              <option value="30days">30 ngày qua</option>
            </select>
          </div>

          {/* Reset Filters */}
          {hasActiveFilters && (
            <button
              type="button"
              onClick={handleReset}
              className="flex items-center gap-1 px-3 py-2 text-xs font-semibold text-rose-600 hover:bg-rose-50 rounded-xl transition-colors cursor-pointer"
              title="Đặt lại bộ lọc"
            >
              <RotateCcw className="w-3.5 h-3.5" />
              <span>Đặt lại</span>
            </button>
          )}
        </div>
      </div>

      <div className="flex items-center justify-between text-xs text-slate-500 pt-1">
        <span>
          Hiển thị <strong className="text-neutral-800 font-mono">{totalResults}</strong> pipeline phù hợp
        </span>
        {hasActiveFilters && (
          <span className="text-[11px] text-sky-600 font-semibold bg-sky-50 px-2 py-0.5 rounded-md">
            Đang áp dụng bộ lọc tùy chỉnh
          </span>
        )}
      </div>
    </div>
  );
};
