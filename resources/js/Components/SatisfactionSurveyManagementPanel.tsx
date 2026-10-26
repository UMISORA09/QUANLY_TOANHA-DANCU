import React, { useState, useEffect, useCallback } from 'react';
import {
  api,
  SatisfactionSurveyItem,
  SurveyOverviewKpis,
  SurveyAnalyticsData,
  CreateSurveyPayload,
  UpdateSurveyPayload
} from '../Services/api';
import {
  BarChart3,
  Calendar,
  CheckCircle2,
  Clock,
  Plus,
  Search,
  Building,
  RefreshCw,
  AlertCircle,
  X,
  PieChart,
  Star,
  Users,
  Percent,
  Sliders,
  Trash2,
  Edit,
  Power,
  ChevronRight,
  TrendingUp,
  Layers,
  HelpCircle,
  MessageSquare
} from 'lucide-react';

export const SatisfactionSurveyManagementPanel: React.FC = () => {
  // State: List & KPIs
  const [surveys, setSurveys] = useState<SatisfactionSurveyItem[]>([]);
  const [kpis, setKpis] = useState<SurveyOverviewKpis>({
    total_surveys: 0,
    active_surveys: 0,
    total_responses: 0,
    overall_average_rating: 0,
    overall_csat_percentage: 0,
    total_valid_ratings: 0,
    dissatisfied_count: 0
  });
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);

  // Filters & Pagination
  const [search, setSearch] = useState<string>('');
  const [statusFilter, setStatusFilter] = useState<string>('ALL');
  const [page, setPage] = useState<number>(1);
  const [totalPages, setTotalPages] = useState<number>(1);

  // Modal: Analytics
  const [analyticsSurveyId, setAnalyticsSurveyId] = useState<string | null>(null);
  const [analyticsData, setAnalyticsData] = useState<SurveyAnalyticsData | null>(null);
  const [analyticsLoading, setAnalyticsLoading] = useState<boolean>(false);

  // Modal: Create / Edit Survey Builder
  const [isFormModalOpen, setIsFormModalOpen] = useState<boolean>(false);
  const [editingSurvey, setEditingSurvey] = useState<SatisfactionSurveyItem | null>(null);
  const [formSubmitting, setFormSubmitting] = useState<boolean>(false);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  // Form Fields
  const [formCode, setFormCode] = useState<string>('');
  const [formTitle, setFormTitle] = useState<string>('');
  const [formDescription, setFormDescription] = useState<string>('');
  const [formTargetBlockId, setFormTargetBlockId] = useState<string>('');
  const [formStartDate, setFormStartDate] = useState<string>('');
  const [formEndDate, setFormEndDate] = useState<string>('');
  const [formIsActive, setFormIsActive] = useState<boolean>(true);
  const [formQuestions, setFormQuestions] = useState<Array<{
    question_text: string;
    question_type: 'RATING_1_TO_5' | 'YES_NO' | 'MULTIPLE_CHOICE' | 'FREE_TEXT';
    options_json?: string[];
    is_required: boolean;
    question_order: number;
    rawOptions?: string;
  }>>([]);

  // OCC Conflict Modal
  const [occConflict, setOccConflict] = useState<string | null>(null);

  // Fetch survey list and aggregate KPIs
  const loadSurveys = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await api.getManagementSurveys({
        search,
        status: statusFilter,
        page
      });
      setSurveys(res.data || []);
      if (res.kpis) setKpis(res.kpis);
      if (res.meta) setTotalPages(res.meta.last_page);
    } catch (err: any) {
      setError(err?.message || 'Không thể tải danh sách khảo sát.');
    } finally {
      setLoading(false);
    }
  }, [search, statusFilter, page]);

  useEffect(() => {
    loadSurveys();
  }, [loadSurveys]);

  // Open Analytics View
  const handleOpenAnalytics = async (surveyId: string) => {
    setAnalyticsSurveyId(surveyId);
    setAnalyticsLoading(true);
    try {
      const res = await api.getManagementSurveyAnalytics(surveyId);
      setAnalyticsData(res.data);
    } catch (err: any) {
      alert(err?.message || 'Không thể tải báo cáo phân tích CSAT.');
      setAnalyticsSurveyId(null);
    } finally {
      setAnalyticsLoading(false);
    }
  };

  // Open Create Modal
  const handleOpenCreateModal = () => {
    setEditingSurvey(null);
    setFormCode(`SURVEY-${new Date().getFullYear()}-Q${Math.floor(new Date().getMonth() / 3) + 1}-${Math.floor(100 + Math.random() * 900)}`);
    setFormTitle('');
    setFormDescription('');
    setFormTargetBlockId('');
    const today = new Date().toISOString().split('T')[0];
    const thirtyDaysLater = new Date(Date.now() + 30 * 86400000).toISOString().split('T')[0];
    setFormStartDate(today);
    setFormEndDate(thirtyDaysLater);
    setFormIsActive(true);
    setFormQuestions([
      {
        question_text: 'Đánh giá mức độ hài lòng chung về dịch vụ quản lý tòa nhà:',
        question_type: 'RATING_1_TO_5',
        is_required: true,
        question_order: 1
      },
      {
        question_text: 'Bạn có hài lòng với tốc độ giải quyết sự cố kỹ thuật của Ban Quản lý?',
        question_type: 'YES_NO',
        is_required: true,
        question_order: 2,
        options_json: ['Có', 'Không']
      },
      {
        question_text: 'Ý kiến đóng góp xây dựng nếp sống văn minh đô thị:',
        question_type: 'FREE_TEXT',
        is_required: false,
        question_order: 3
      }
    ]);
    setFormErrors({});
    setIsFormModalOpen(true);
  };

  // Open Edit Modal
  const handleOpenEditModal = (survey: SatisfactionSurveyItem) => {
    setEditingSurvey(survey);
    setFormCode(survey.survey_code);
    setFormTitle(survey.title);
    setFormDescription(survey.description || '');
    setFormTargetBlockId(survey.target_block_id || '');
    setFormStartDate(survey.start_date);
    setFormEndDate(survey.end_date);
    setFormIsActive(survey.is_active);
    const mappedQuestions = (survey.questions || []).map((q, idx) => ({
      question_text: q.question_text,
      question_type: q.question_type,
      options_json: q.options || q.options_json || [],
      rawOptions: (q.options || q.options_json || []).join('\n'),
      is_required: q.is_required,
      question_order: q.question_order || idx + 1
    }));
    setFormQuestions(mappedQuestions);
    setFormErrors({});
    setIsFormModalOpen(true);
  };

  // Question manipulation
  const handleAddQuestion = () => {
    setFormQuestions(prev => [
      ...prev,
      {
        question_text: '',
        question_type: 'RATING_1_TO_5',
        is_required: true,
        question_order: prev.length + 1
      }
    ]);
  };

  const handleRemoveQuestion = (index: number) => {
    setFormQuestions(prev => prev.filter((_, i) => i !== index));
  };

  const handleQuestionChange = (index: number, field: string, val: any) => {
    setFormQuestions(prev => {
      const next = [...prev];
      next[index] = { ...next[index], [field]: val };
      if (field === 'rawOptions') {
        const split = (val as string).split('\n').map(s => s.trim()).filter(Boolean);
        next[index].options_json = split;
      }
      return next;
    });
  };

  // Submit Survey Form (Create or Edit with OCC)
  const handleSubmitForm = async (e: React.FormEvent) => {
    e.preventDefault();
    const errors: Record<string, string> = {};

    if (!formTitle.trim()) errors.title = 'Tiêu đề khảo sát là bắt buộc.';
    if (!formStartDate) errors.start_date = 'Ngày bắt đầu là bắt buộc.';
    if (!formEndDate) errors.end_date = 'Ngày kết thúc là bắt buộc.';
    if (formStartDate && formEndDate && formEndDate < formStartDate) {
      errors.end_date = 'Ngày kết thúc không được trước ngày bắt đầu.';
    }
    if (formQuestions.length === 0) {
      errors.questions = 'Khảo sát phải có ít nhất 1 câu hỏi.';
    }

    formQuestions.forEach((q, idx) => {
      if (!q.question_text.trim()) {
        errors[`q_${idx}`] = `Nội dung câu hỏi #${idx + 1} không được để trống.`;
      }
    });

    if (Object.keys(errors).length > 0) {
      setFormErrors(errors);
      return;
    }

    setFormSubmitting(true);
    try {
      const formattedQuestions = formQuestions.map((q, idx) => ({
        question_text: q.question_text.trim(),
        question_type: q.question_type,
        options_json: q.options_json || (q.rawOptions ? q.rawOptions.split('\n').map(s => s.trim()).filter(Boolean) : []),
        is_required: q.is_required,
        question_order: idx + 1
      }));

      if (editingSurvey) {
        // Update with OCC version check
        const payload: UpdateSurveyPayload = {
          version: editingSurvey.version,
          title: formTitle.trim(),
          description: formDescription.trim() || undefined,
          target_block_id: formTargetBlockId || null,
          start_date: formStartDate,
          end_date: formEndDate,
          is_active: formIsActive,
          // Only send questions if survey hasn't received responses
          questions: (editingSurvey.responses_count ?? 0) === 0 ? formattedQuestions : undefined
        };
        await api.updateManagementSurvey(editingSurvey.id, payload);
      } else {
        // Create
        const payload: CreateSurveyPayload = {
          survey_code: formCode.trim() || undefined,
          title: formTitle.trim(),
          description: formDescription.trim() || undefined,
          target_block_id: formTargetBlockId || null,
          start_date: formStartDate,
          end_date: formEndDate,
          is_active: formIsActive,
          questions: formattedQuestions
        };
        await api.createManagementSurvey(payload);
      }

      setIsFormModalOpen(false);
      loadSurveys();
    } catch (err: any) {
      if (err?.message?.includes('409') || err?.message?.includes('Xung đột phiên bản') || err?.status === 409) {
        setOccConflict(err.message || 'Khảo sát đã được chỉnh sửa bởi một quản lý khác. Vui lòng tải lại dữ liệu mới nhất.');
      } else {
        alert(err?.message || 'Lưu khảo sát thất bại.');
      }
    } finally {
      setFormSubmitting(false);
    }
  };

  // Toggle active status
  const handleToggleActive = async (survey: SatisfactionSurveyItem) => {
    try {
      await api.toggleManagementSurveyStatus(survey.id, !survey.is_active);
      loadSurveys();
    } catch (err: any) {
      alert(err?.message || 'Không thể thay đổi trạng thái khảo sát.');
    }
  };

  // Helper badge color
  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'ACTIVE':
        return <span className="px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">Đang diễn ra</span>;
      case 'UPCOMING':
        return <span className="px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300">Sắp diễn ra</span>;
      case 'CLOSED':
        return <span className="px-2.5 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-300">Đã kết thúc</span>;
      case 'DISABLED':
        return <span className="px-2.5 py-1 rounded-full text-xs font-semibold bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300">Vô hiệu hóa</span>;
      default:
        return null;
    }
  };

  return (
    <div className="space-y-6">
      {/* Top Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
            <BarChart3 className="w-7 h-7 text-emerald-600" />
            Khảo sát & Thống kê Mức độ Hài lòng Cư dân (CSAT)
          </h1>
          <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
            Đo lường mức độ thỏa dụng của cư dân, giám sát chỉ số CSAT định kỳ và phân tích ý kiến đóng góp theo tòa nhà.
          </p>
        </div>
        <div className="flex items-center gap-3">
          <button
            onClick={loadSurveys}
            className="p-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white transition-colors"
            title="Làm mới"
          >
            <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
          </button>
          <button
            onClick={handleOpenCreateModal}
            className="inline-flex items-center gap-2 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-sm rounded-xl transition-all shadow-sm"
          >
            <Plus className="w-4 h-4" />
            Tạo chiến dịch khảo sát
          </button>
        </div>
      </div>

      {/* KPI Cards */}
      <div className="grid grid-cols-2 lg:grid-cols-6 gap-4">
        <div className="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm space-y-2">
          <div className="flex items-center justify-between text-slate-500 text-xs font-medium">
            <span>Tổng khảo sát</span>
            <Layers className="w-4 h-4 text-emerald-500" />
          </div>
          <div className="text-2xl font-bold text-slate-900 dark:text-white">
            {kpis.total_surveys}
          </div>
        </div>

        <div className="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm space-y-2">
          <div className="flex items-center justify-between text-slate-500 text-xs font-medium">
            <span>Đang mở</span>
            <Clock className="w-4 h-4 text-blue-500" />
          </div>
          <div className="text-2xl font-bold text-blue-600 dark:text-blue-400">
            {kpis.active_surveys}
          </div>
        </div>

        <div className="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm space-y-2">
          <div className="flex items-center justify-between text-slate-500 text-xs font-medium">
            <span>Tổng lượt đánh giá</span>
            <Users className="w-4 h-4 text-purple-500" />
          </div>
          <div className="text-2xl font-bold text-slate-900 dark:text-white">
            {kpis.total_responses}
          </div>
        </div>

        <div className="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm space-y-2">
          <div className="flex items-center justify-between text-slate-500 text-xs font-medium">
            <span>Điểm trung bình</span>
            <Star className="w-4 h-4 text-amber-500 fill-amber-500" />
          </div>
          <div className="text-2xl font-bold text-amber-600 dark:text-amber-400">
            {kpis.overall_average_rating > 0 ? `${kpis.overall_average_rating}/5` : 'N/A'}
          </div>
        </div>

        <div className="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm space-y-2">
          <div className="flex items-center justify-between text-slate-500 text-xs font-medium">
            <span>CSAT Score</span>
            <TrendingUp className="w-4 h-4 text-emerald-500" />
          </div>
          <div className="text-2xl font-bold text-emerald-600 dark:text-emerald-400">
            {kpis.total_valid_ratings > 0 ? `${kpis.overall_csat_percentage}%` : 'N/A'}
          </div>
        </div>

        <div className="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm space-y-2">
          <div className="flex items-center justify-between text-slate-500 text-xs font-medium">
            <span>Không hài lòng (1-2★)</span>
            <AlertCircle className="w-4 h-4 text-rose-500" />
          </div>
          <div className="text-2xl font-bold text-rose-600 dark:text-rose-400">
            {kpis.dissatisfied_count}
          </div>
        </div>
      </div>

      {/* Filter Toolbar */}
      <div className="bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm flex flex-col sm:flex-row gap-3 items-center justify-between">
        <div className="relative w-full sm:w-80">
          <Search className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Tìm theo tên hoặc mã chiến dịch..."
            className="w-full pl-9 pr-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500"
          />
        </div>

        <div className="flex items-center gap-3 w-full sm:w-auto">
          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 text-slate-700 dark:text-slate-300"
          >
            <option value="ALL">Tất cả trạng thái</option>
            <option value="ACTIVE">Đang diễn ra</option>
            <option value="UPCOMING">Sắp diễn ra</option>
            <option value="CLOSED">Đã kết thúc</option>
            <option value="DISABLED">Đã vô hiệu hóa</option>
          </select>
        </div>
      </div>

      {/* Error alert */}
      {error && (
        <div className="p-4 bg-rose-50 border border-rose-200 rounded-xl text-rose-700 text-sm flex items-center gap-2">
          <AlertCircle className="w-5 h-5 flex-shrink-0" />
          <span>{error}</span>
        </div>
      )}

      {/* Surveys Table */}
      <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="bg-slate-50 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold text-slate-500 uppercase tracking-wider">
                <th className="py-3.5 px-4">Khảo sát & Mã</th>
                <th className="py-3.5 px-4">Tòa nhà</th>
                <th className="py-3.5 px-4">Lịch khảo sát</th>
                <th className="py-3.5 px-4">Trạng thái</th>
                <th className="py-3.5 px-4">Lượt tham gia</th>
                <th className="py-3.5 px-4 text-right">Thao tác</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800 text-sm">
              {loading ? (
                <tr>
                  <td colSpan={6} className="py-12 text-center text-slate-500">
                    <RefreshCw className="w-6 h-6 animate-spin mx-auto text-emerald-600 mb-2" />
                    Đang tải dữ liệu...
                  </td>
                </tr>
              ) : surveys.length === 0 ? (
                <tr>
                  <td colSpan={6} className="py-12 text-center text-slate-500">
                    Không tìm thấy chiến dịch khảo sát nào phù hợp.
                  </td>
                </tr>
              ) : (
                surveys.map((survey) => (
                  <tr key={survey.id} className="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition-colors">
                    <td className="py-3.5 px-4">
                      <div className="font-semibold text-slate-900 dark:text-white">
                        {survey.title}
                      </div>
                      <div className="text-xs text-slate-400 font-mono mt-0.5">
                        {survey.survey_code}
                      </div>
                    </td>
                    <td className="py-3.5 px-4">
                      <span className="inline-flex items-center gap-1.5 text-xs text-slate-600 dark:text-slate-300">
                        <Building className="w-3.5 h-3.5 text-slate-400" />
                        {survey.target_block?.block_name || 'Toàn khu đô thị'}
                      </span>
                    </td>
                    <td className="py-3.5 px-4 text-xs text-slate-500">
                      <div>Từ: {survey.start_date}</div>
                      <div>Đến: {survey.end_date}</div>
                    </td>
                    <td className="py-3.5 px-4">
                      {getStatusBadge(survey.computed_status)}
                    </td>
                    <td className="py-3.5 px-4">
                      <div className="font-semibold text-slate-900 dark:text-white">
                        {survey.responses_count ?? 0} phiếu
                      </div>
                      <div className="text-xs text-slate-400">
                        Tỷ lệ: {survey.participation_rate_percent ?? 0}%
                      </div>
                    </td>
                    <td className="py-3.5 px-4 text-right">
                      <div className="inline-flex items-center gap-2">
                        <button
                          onClick={() => handleOpenAnalytics(survey.id)}
                          className="inline-flex items-center gap-1 px-3 py-1.5 bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-100 rounded-lg text-xs font-semibold transition-colors"
                          title="Xem biểu đồ & Thống kê CSAT"
                        >
                          <BarChart3 className="w-3.5 h-3.5" />
                          Phân tích CSAT
                        </button>
                        <button
                          onClick={() => handleOpenEditModal(survey)}
                          className="p-1.5 text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                          title="Chỉnh sửa cấu hình"
                        >
                          <Edit className="w-4 h-4" />
                        </button>
                        <button
                          onClick={() => handleToggleActive(survey)}
                          className={`p-1.5 rounded-lg transition-colors ${
                            survey.is_active
                              ? 'text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/40'
                              : 'text-emerald-500 hover:bg-emerald-50 dark:hover:bg-emerald-950/40'
                          }`}
                          title={survey.is_active ? 'Tạm dừng nhận phản hồi' : 'Kích hoạt khảo sát'}
                        >
                          <Power className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* MODAL 1: Create / Edit Survey Builder */}
      {isFormModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm overflow-y-auto">
          <div className="bg-white dark:bg-slate-900 rounded-2xl max-w-3xl w-full border border-slate-200 dark:border-slate-800 shadow-2xl overflow-hidden my-8">
            <div className="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
              <div>
                <h3 className="text-lg font-bold text-slate-900 dark:text-white">
                  {editingSurvey ? 'Chỉnh sửa Chiến dịch Khảo sát' : 'Thiết lập Chiến dịch Khảo sát Mới'}
                </h3>
                <p className="text-xs text-slate-500">
                  {editingSurvey ? `Phiên bản hiện tại: v${editingSurvey.version}` : 'Cấu hình tiêu đề, thời gian và danh mục câu hỏi'}
                </p>
              </div>
              <button
                type="button"
                data-testid="close-survey-builder-modal"
                aria-label="Đóng biểu mẫu khảo sát"
                title="Đóng"
                onClick={() => setIsFormModalOpen(false)}
                className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 rounded-lg p-1.5 transition-colors"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleSubmitForm} className="p-6 space-y-5 max-h-[80vh] overflow-y-auto">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                    Mã khảo sát (Duy nhất) <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="text"
                    value={formCode}
                    onChange={(e) => setFormCode(e.target.value)}
                    disabled={!!editingSurvey}
                    className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-mono focus:ring-2 focus:ring-emerald-500 disabled:opacity-50"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                    Tòa nhà áp dụng
                  </label>
                  <select
                    value={formTargetBlockId}
                    onChange={(e) => setFormTargetBlockId(e.target.value)}
                    className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-emerald-500 text-slate-700 dark:text-slate-300"
                  >
                    <option value="">Toàn khu đô thị (Mọi tòa)</option>
                    {/* Dynamic blocks can be selected */}
                  </select>
                </div>
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                  Tiêu đề cuộc khảo sát <span className="text-rose-500">*</span>
                </label>
                <input
                  type="text"
                  value={formTitle}
                  onChange={(e) => setFormTitle(e.target.value)}
                  placeholder="Ví dụ: Khảo sát Mức độ Hài lòng Dịch vụ Quý 4/2026..."
                  className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-emerald-500"
                />
                {formErrors.title && <p className="text-xs text-rose-500 mt-1">{formErrors.title}</p>}
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                  Mô tả mục đích khảo sát
                </label>
                <textarea
                  value={formDescription}
                  onChange={(e) => setFormDescription(e.target.value)}
                  rows={2}
                  className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-emerald-500 resize-none"
                />
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                    Ngày bắt đầu <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="date"
                    value={formStartDate}
                    onChange={(e) => setFormStartDate(e.target.value)}
                    className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-emerald-500"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                    Ngày kết thúc <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="date"
                    value={formEndDate}
                    onChange={(e) => setFormEndDate(e.target.value)}
                    className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-emerald-500"
                  />
                  {formErrors.end_date && <p className="text-xs text-rose-500 mt-1">{formErrors.end_date}</p>}
                </div>
              </div>

              {/* Question Builder */}
              <div className="pt-4 border-t border-slate-100 dark:border-slate-800 space-y-4">
                <div className="flex items-center justify-between">
                  <div>
                    <h4 className="text-sm font-bold text-slate-900 dark:text-white">
                      Danh sách Câu hỏi ({formQuestions.length})
                    </h4>
                    {editingSurvey && (editingSurvey.responses_count ?? 0) > 0 && (
                      <p className="text-xs text-amber-600 dark:text-amber-400 mt-0.5">
                        * Khảo sát đã có người tham gia: Danh sách câu hỏi đã được khóa để bảo toàn tính toàn vẹn dữ liệu.
                      </p>
                    )}
                  </div>
                  {(!editingSurvey || (editingSurvey.responses_count ?? 0) === 0) && (
                    <button
                      type="button"
                      onClick={handleAddQuestion}
                      className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-emerald-600 text-emerald-600 hover:bg-emerald-50 text-xs font-semibold transition-colors"
                    >
                      <Plus className="w-3.5 h-3.5" /> Thêm câu hỏi
                    </button>
                  )}
                </div>

                {formErrors.questions && (
                  <p className="text-xs text-rose-500">{formErrors.questions}</p>
                )}

                <div className="space-y-4">
                  {formQuestions.map((q, idx) => (
                    <div
                      key={idx}
                      className="p-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/40 space-y-3"
                    >
                      <div className="flex items-center justify-between gap-3">
                        <span className="text-xs font-bold text-slate-600 dark:text-slate-300">
                          Câu hỏi #{idx + 1}
                        </span>
                        {(!editingSurvey || (editingSurvey.responses_count ?? 0) === 0) && (
                          <button
                            type="button"
                            onClick={() => handleRemoveQuestion(idx)}
                            className="text-slate-400 hover:text-rose-500 transition-colors"
                          >
                            <Trash2 className="w-4 h-4" />
                          </button>
                        )}
                      </div>

                      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div className="sm:col-span-2">
                          <input
                            type="text"
                            value={q.question_text}
                            onChange={(e) => handleQuestionChange(idx, 'question_text', e.target.value)}
                            disabled={editingSurvey ? (editingSurvey.responses_count ?? 0) > 0 : false}
                            placeholder="Nhập nội dung câu hỏi..."
                            className="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-emerald-500 disabled:opacity-50"
                          />
                        </div>

                        <div>
                          <select
                            value={q.question_type}
                            onChange={(e) => handleQuestionChange(idx, 'question_type', e.target.value)}
                            disabled={editingSurvey ? (editingSurvey.responses_count ?? 0) > 0 : false}
                            className="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-emerald-500 text-slate-700 dark:text-slate-300 disabled:opacity-50"
                          >
                            <option value="RATING_1_TO_5">Đánh giá 1-5 sao (CSAT)</option>
                            <option value="YES_NO">Câu hỏi Có / Không</option>
                            <option value="MULTIPLE_CHOICE">Trắc nghiệm lựa chọn</option>
                            <option value="FREE_TEXT">Tự luận góp ý</option>
                          </select>
                        </div>
                      </div>

                      {q.question_type === 'MULTIPLE_CHOICE' && (
                        <div>
                          <label className="block text-xs font-medium text-slate-500 mb-1">
                            Các phương án (Mỗi phương án 1 dòng):
                          </label>
                          <textarea
                            value={q.rawOptions || (q.options_json || []).join('\n')}
                            onChange={(e) => handleQuestionChange(idx, 'rawOptions', e.target.value)}
                            disabled={editingSurvey ? (editingSurvey.responses_count ?? 0) > 0 : false}
                            rows={3}
                            placeholder="Phương án A&#10;Phương án B&#10;Phương án C"
                            className="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs font-mono focus:ring-2 focus:ring-emerald-500 resize-none disabled:opacity-50"
                          />
                        </div>
                      )}

                      <div className="flex items-center gap-2">
                        <input
                          type="checkbox"
                          id={`req_${idx}`}
                          checked={q.is_required}
                          onChange={(e) => handleQuestionChange(idx, 'is_required', e.target.checked)}
                          disabled={editingSurvey ? (editingSurvey.responses_count ?? 0) > 0 : false}
                          className="w-4 h-4 text-emerald-600 rounded focus:ring-emerald-500"
                        />
                        <label htmlFor={`req_${idx}`} className="text-xs text-slate-600 dark:text-slate-300 cursor-pointer">
                          Bắt buộc trả lời
                        </label>
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              {/* Submit Footer */}
              <div className="pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-3">
                <button
                  type="button"
                  onClick={() => setIsFormModalOpen(false)}
                  className="px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 text-sm font-medium hover:bg-slate-50 transition-colors"
                >
                  Hủy
                </button>
                <button
                  type="submit"
                  disabled={formSubmitting}
                  className="px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold shadow-sm transition-all disabled:opacity-50"
                >
                  {formSubmitting ? 'Đang lưu...' : editingSurvey ? 'Lưu cập nhật' : 'Tạo khảo sát'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* MODAL 2: Comprehensive CSAT Analytics Dashboard */}
      {analyticsSurveyId && analyticsData && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm overflow-y-auto">
          <div className="bg-white dark:bg-slate-900 rounded-2xl max-w-4xl w-full border border-slate-200 dark:border-slate-800 shadow-2xl overflow-hidden my-8">
            {/* Header */}
            <div className="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
              <div>
                <span className="text-xs font-mono font-bold text-emerald-600 bg-emerald-50 dark:bg-emerald-950/60 px-2.5 py-0.5 rounded-full">
                  {analyticsData.survey.survey_code}
                </span>
                <h3 className="text-xl font-bold text-slate-900 dark:text-white mt-1">
                  Báo cáo Thống kê & Phân tích CSAT: {analyticsData.survey.title}
                </h3>
                <p className="text-xs text-slate-500 mt-0.5">
                  Phạm vi: {analyticsData.survey.target_block_name} | Thời gian: {analyticsData.survey.start_date} đến {analyticsData.survey.end_date}
                </p>
              </div>
              <button
                type="button"
                data-testid="close-analytics-modal"
                aria-label="Đóng báo cáo phân tích"
                title="Đóng"
                onClick={() => setAnalyticsSurveyId(null)}
                className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 rounded-lg p-1.5 transition-colors"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="p-6 space-y-6 max-h-[80vh] overflow-y-auto">
              {/* Top Summary Cards */}
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div className="bg-slate-50 dark:bg-slate-800/60 p-4 rounded-xl border border-slate-100 dark:border-slate-800">
                  <div className="text-xs text-slate-500 font-medium">Lượt gửi / Căn hộ</div>
                  <div className="text-2xl font-bold text-slate-900 dark:text-white mt-1">
                    {analyticsData.kpis.total_responses} <span className="text-xs text-slate-400 font-normal">/ {analyticsData.kpis.eligible_apartments_count}</span>
                  </div>
                  <div className="text-xs text-slate-400 mt-0.5">Tỷ lệ: {analyticsData.kpis.participation_rate_percent}%</div>
                </div>

                <div className="bg-emerald-50/60 dark:bg-emerald-950/40 p-4 rounded-xl border border-emerald-100 dark:border-emerald-800">
                  <div className="text-xs text-emerald-700 dark:text-emerald-400 font-semibold">Chỉ số CSAT</div>
                  <div className="text-2xl font-extrabold text-emerald-700 dark:text-emerald-400 mt-1">
                    {analyticsData.kpis.total_valid_ratings > 0 ? `${analyticsData.kpis.overall_csat_percentage}%` : 'N/A'}
                  </div>
                  <div className="text-xs text-emerald-600/80 mt-0.5">Đánh giá 4★ & 5★</div>
                </div>

                <div className="bg-amber-50/60 dark:bg-amber-950/40 p-4 rounded-xl border border-amber-100 dark:border-amber-800">
                  <div className="text-xs text-amber-700 dark:text-amber-400 font-semibold">Điểm đánh giá TB</div>
                  <div className="text-2xl font-extrabold text-amber-700 dark:text-amber-400 mt-1">
                    {analyticsData.kpis.overall_average_rating > 0 ? `${analyticsData.kpis.overall_average_rating} / 5` : 'N/A'}
                  </div>
                  <div className="text-xs text-amber-600/80 mt-0.5">Thang điểm 1-5 sao</div>
                </div>

                <div className="bg-rose-50/60 dark:bg-rose-950/40 p-4 rounded-xl border border-rose-100 dark:border-rose-800">
                  <div className="text-xs text-rose-700 dark:text-rose-400 font-semibold">Không hài lòng</div>
                  <div className="text-2xl font-extrabold text-rose-700 dark:text-rose-400 mt-1">
                    {analyticsData.kpis.dissatisfied_ratings_count}
                  </div>
                  <div className="text-xs text-rose-600/80 mt-0.5">Đánh giá 1★ & 2★</div>
                </div>
              </div>

              {/* Per-Question Detailed Analytics */}
              <div className="space-y-6">
                <h4 className="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                  <PieChart className="w-5 h-5 text-emerald-600" />
                  Chi tiết kết quả theo từng câu hỏi
                </h4>

                {analyticsData.question_stats.map((qStat) => (
                  <div
                    key={qStat.question_id}
                    className="p-5 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm space-y-4"
                  >
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div>
                        <span className="text-xs font-bold text-slate-400">Câu hỏi #{qStat.question_order}</span>
                        <h5 className="text-sm font-semibold text-slate-900 dark:text-white mt-0.5">
                          {qStat.question_text}
                        </h5>
                      </div>
                      <div className="text-xs text-slate-500 font-medium bg-slate-100 dark:bg-slate-800 px-2.5 py-1 rounded-lg">
                        {qStat.total_answers} câu trả lời
                      </div>
                    </div>

                    {/* Rating 1-5 Visualization */}
                    {qStat.question_type === 'RATING_1_TO_5' && (
                      <div className="space-y-3 pt-2">
                        <div className="flex flex-wrap gap-4 text-xs font-medium">
                          <span className="text-emerald-600 font-bold">CSAT: {qStat.csat_percentage ?? 0}%</span>
                          <span className="text-amber-600 font-bold">Điểm TB: {qStat.average_rating ?? 0}/5</span>
                          <span className="text-slate-500">Hài lòng: {qStat.satisfied_count}</span>
                          <span className="text-slate-500">Trung lập: {qStat.neutral_count}</span>
                          <span className="text-slate-500">Không hài lòng: {qStat.dissatisfied_count}</span>
                        </div>

                        {/* Visual SVG Score Distribution Bars */}
                        <div className="space-y-1.5 pt-1">
                          {(qStat.rating_distribution || []).map((dist) => (
                            <div key={dist.stars} className="flex items-center gap-3 text-xs">
                              <span className="w-12 font-medium text-slate-600 dark:text-slate-400 flex items-center gap-1">
                                {dist.stars} <Star className="w-3 h-3 text-amber-500 fill-amber-500" />
                              </span>
                              <div className="flex-1 h-3 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                                <div
                                  className={`h-full rounded-full transition-all duration-500 ${
                                    dist.stars >= 4
                                      ? 'bg-emerald-500'
                                      : dist.stars === 3
                                      ? 'bg-amber-400'
                                      : 'bg-rose-400'
                                  }`}
                                  style={{ width: `${dist.percent}%` }}
                                />
                              </div>
                              <span className="w-16 text-right font-mono text-slate-500">
                                {dist.count} ({dist.percent}%)
                              </span>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}

                    {/* YES_NO Visualization */}
                    {qStat.question_type === 'YES_NO' && (
                      <div className="grid grid-cols-2 gap-4 pt-2">
                        <div className="p-3 bg-emerald-50 dark:bg-emerald-950/40 rounded-xl border border-emerald-100 dark:border-emerald-900 text-center">
                          <div className="text-xs font-semibold text-emerald-800 dark:text-emerald-300">CÓ</div>
                          <div className="text-xl font-extrabold text-emerald-600 mt-0.5">{qStat.yes_percentage}%</div>
                          <div className="text-xs text-emerald-500">{qStat.yes_count} lựa chọn</div>
                        </div>
                        <div className="p-3 bg-rose-50 dark:bg-rose-950/40 rounded-xl border border-rose-100 dark:border-rose-900 text-center">
                          <div className="text-xs font-semibold text-rose-800 dark:text-rose-300">KHÔNG</div>
                          <div className="text-xl font-extrabold text-rose-600 mt-0.5">{qStat.no_percentage}%</div>
                          <div className="text-xs text-rose-500">{qStat.no_count} lựa chọn</div>
                        </div>
                      </div>
                    )}

                    {/* MULTIPLE_CHOICE Visualization */}
                    {qStat.question_type === 'MULTIPLE_CHOICE' && (
                      <div className="space-y-2 pt-2">
                        {(qStat.options_distribution || []).map((optDist, oIdx) => (
                          <div key={oIdx} className="space-y-1 text-xs">
                            <div className="flex justify-between font-medium text-slate-700 dark:text-slate-300">
                              <span>{optDist.option}</span>
                              <span className="font-mono">{optDist.count} ({optDist.percent}%)</span>
                            </div>
                            <div className="w-full h-2.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                              <div
                                className="h-full bg-blue-500 rounded-full transition-all duration-300"
                                style={{ width: `${optDist.percent}%` }}
                              />
                            </div>
                          </div>
                        ))}
                      </div>
                    )}

                    {/* FREE_TEXT Responses */}
                    {qStat.question_type === 'FREE_TEXT' && (
                      <div className="space-y-2 pt-2">
                        {(qStat.text_responses || []).length === 0 ? (
                          <p className="text-xs text-slate-400 italic">Chưa có ý kiến đóng góp dạng văn bản.</p>
                        ) : (
                          <div className="space-y-2 max-h-48 overflow-y-auto pr-1">
                            {qStat.text_responses?.map((item) => (
                              <div
                                key={item.id}
                                className="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-800 text-xs text-slate-700 dark:text-slate-300 leading-relaxed"
                              >
                                <p>"{item.answer}"</p>
                                <span className="block text-[10px] text-slate-400 mt-1">{item.created_at}</span>
                              </div>
                            ))}
                          </div>
                        )}
                      </div>
                    )}
                  </div>
                ))}
              </div>
            </div>

            <div className="p-4 bg-slate-50 dark:bg-slate-800/60 border-t border-slate-100 dark:border-slate-800 flex justify-end">
              <button
                type="button"
                onClick={() => setAnalyticsSurveyId(null)}
                className="px-5 py-2 bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 rounded-xl text-sm font-medium transition-colors"
              >
                Đóng
              </button>
            </div>
          </div>
        </div>
      )}

      {/* OCC Conflict Dialog */}
      {occConflict && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
          <div className="bg-white dark:bg-slate-900 rounded-2xl max-w-md w-full p-6 border border-rose-200 dark:border-rose-900 shadow-xl space-y-4">
            <div className="flex items-center gap-3 text-rose-600">
              <AlertCircle className="w-6 h-6 flex-shrink-0" />
              <h3 className="text-lg font-bold">Xung đột phiên bản (409 Conflict)</h3>
            </div>
            <p className="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
              {occConflict}
            </p>
            <div className="flex justify-end gap-3 pt-2">
              <button
                onClick={() => {
                  setOccConflict(null);
                  setIsFormModalOpen(false);
                  loadSurveys();
                }}
                className="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-sm font-semibold transition-colors"
              >
                Tải lại dữ liệu mới nhất
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
