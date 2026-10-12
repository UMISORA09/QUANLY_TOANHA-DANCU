import React, { useState, useEffect } from 'react';
import { Search, Filter, RotateCcw, GitBranch, Workflow, CheckCircle2, Clock } from 'lucide-react';
import { useDebounce } from '../../Hooks/useDebounce';

export interface FilterState {
  search: string;
  status: string;
  branch: string;
  workflow: string;
  dateRange: string;
  author: string;
}

interface PipelineFiltersProps {
  onFilterChange: (filters: FilterState) => void;
  availableBranches?: string[];
  availableWorkflows?: { file: string; name: string }[];
  availableAuthors?: { login: string; name: string }[];
  totalResults: number;
}

export const PipelineFilters: React.FC<PipelineFiltersProps> = ({
  onFilterChange,
  availableBranches = ['all', 'main', 'master', 'DangNguyen/CI-CD', 'DangNguyen/amenity-management', 'feature/search'],
  availableWorkflows = [],
  availableAuthors = [],
  totalResults,
}) => {
  const [search, setSearch] = useState<string>('');
  const [status, setStatus] = useState<string>('all');
  const [branch, setBranch] = useState<string>('all');
  const [workflow, setWorkflow] = useState<string>('all');
  const [dateRange, setDateRange] = useState<string>('all');
  const [author, setAuthor] = useState('all');

  // Debounce search query 300ms as required
  const debouncedSearch = useDebounce(search, 300);

  useEffect(() => {
    onFilterChange({
      search: debouncedSearch,
      status,
      branch,
      workflow,
      dateRange,
      author,
    });
  }, [debouncedSearch, status, branch, workflow, dateRange, author]);

  const handleReset = () => {
    setSearch('');
    setStatus('all');
    setBranch('all');
    setWorkflow('all');
    setDateRange('all');
    setAuthor('all');
  };

  const hasActiveFilters =
    search.trim() !== '' ||
    status !== 'all' ||
    branch !== 'all' ||
    workflow !== 'all' ||
    dateRange !== 'all' || author !== 'all';

  return (
    <div className="rounded-2xl bg-white/80 backdrop-blur-md p-4 border border-slate-200/80 shadow-xs space-y-3">
      {/* Top Bar: Search Input & Reset Button */}
      <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
        <div className="relative flex-1 min-w-0">
          <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Tìm theo Commit SHA, Nhánh, Tác giả, Thông điệp..."
            aria-label="Tìm pipeline"
            className="w-full pl-10 pr-4 py-2 text-xs sm:text-sm rounded-xl bg-slate-50 hover:bg-slate-100/80 focus:bg-white border border-slate-200 focus:border-sky-500 focus:ring-2 focus:ring-sky-100 transition-all outline-none font-medium placeholder:text-slate-400"
          />
          {search && (
            <button
              type="button"
              onClick={() => setSearch('')}
              aria-label="Xóa tìm kiếm"
              className="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400 hover:text-slate-600 font-bold"
            >
              &times;
            </button>
          )}
        </div>

        {hasActiveFilters && (
          <button
            type="button"
            onClick={handleReset}
            className="flex items-center justify-center gap-1.5 px-3.5 py-2 text-xs font-semibold text-rose-600 hover:bg-rose-50 border border-rose-200/80 rounded-xl transition-colors cursor-pointer shrink-0"
            title="Đặt lại bộ lọc"
          >
            <RotateCcw className="w-3.5 h-3.5" />
            <span>Đặt lại bộ lọc</span>
          </button>
        )}
      </div>

      {/* Dropdown Filters: 5 columns filling 100% width evenly */}
      <div className="grid min-w-0 grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2.5 w-full">
        {/* Author Filter */}
        <div className="flex min-w-0 max-w-full items-center">
          <select
            aria-label="Lọc tác giả pipeline"
            value={author}
            onChange={(event) => setAuthor(event.target.value)}
            className="w-full min-w-0 max-w-full truncate text-xs font-semibold px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 hover:bg-slate-100/80 text-neutral-800 focus:outline-none focus:ring-2 focus:ring-sky-100 cursor-pointer"
          >
            <option value="all">Tác giả: Tất cả</option>
            {availableAuthors.map((item) => (
              <option key={item.login} value={item.login}>
                {item.name} (@{item.login})
              </option>
            ))}
          </select>
        </div>

        {/* Status Filter */}
        <div className="flex min-w-0 max-w-full items-center">
          <select
            value={status}
            aria-label="Lọc trạng thái pipeline"
            onChange={(e) => setStatus(e.target.value)}
            className="w-full min-w-0 max-w-full truncate text-xs font-semibold px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 hover:bg-slate-100/80 text-neutral-800 focus:outline-none focus:ring-2 focus:ring-sky-100 cursor-pointer"
          >
            <option value="all">Trạng thái: Tất cả</option>
            <option value="success">Thành công (Success)</option>
            <option value="failed">Thất bại (Failed)</option>
            <option value="running">Đang chạy (Running)</option>
            <option value="cancelled">Đã hủy (Cancelled)</option>
          </select>
        </div>

        {/* Branch Filter */}
        <div className="flex min-w-0 max-w-full items-center">
          <select
            value={branch}
            aria-label="Lọc nhánh pipeline"
            onChange={(e) => setBranch(e.target.value)}
            className="w-full min-w-0 max-w-full truncate text-xs font-semibold px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 hover:bg-slate-100/80 text-neutral-800 focus:outline-none focus:ring-2 focus:ring-sky-100 cursor-pointer"
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
        <div className="flex min-w-0 max-w-full items-center">
          <select
            value={workflow}
            aria-label="Lọc workflow"
            onChange={(e) => setWorkflow(e.target.value)}
            className="w-full min-w-0 max-w-full truncate text-xs font-semibold px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 hover:bg-slate-100/80 text-neutral-800 focus:outline-none focus:ring-2 focus:ring-sky-100 cursor-pointer"
          >
            <option value="all">Workflow: Tất cả</option>
            {availableWorkflows.map((item) => (
              <option key={item.file} value={item.file}>
                {item.name}
              </option>
            ))}
          </select>
        </div>

        {/* Date Filter */}
        <div className="flex min-w-0 max-w-full items-center col-span-2 sm:col-span-1 lg:col-span-1">
          <select
            value={dateRange}
            aria-label="Lọc thời gian pipeline"
            onChange={(e) => setDateRange(e.target.value)}
            className="w-full min-w-0 max-w-full truncate text-xs font-semibold px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 hover:bg-slate-100/80 text-neutral-800 focus:outline-none focus:ring-2 focus:ring-sky-100 cursor-pointer"
          >
            <option value="all">Thời gian: Tất cả</option>
            <option value="today">Hôm nay</option>
            <option value="7days">7 ngày qua</option>
            <option value="30days">30 ngày qua</option>
          </select>
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
