import React, { useState, useEffect, useCallback } from 'react';
import { api, ResidentSurveyItem, ResidentSurveyDetailResponse, SurveyQuestionItem } from '../Services/api';
import {
  ClipboardCheck,
  Calendar,
  Building,
  CheckCircle2,
  AlertCircle,
  Clock,
  Star,
  ChevronRight,
  ArrowLeft,
  Send,
  Loader2,
  Sparkles,
  Info
} from 'lucide-react';

export const ResidentSatisfactionSurveyPanel: React.FC = () => {
  const [surveys, setSurveys] = useState<ResidentSurveyItem[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);

  // Survey taking view
  const [activeSurveyId, setActiveSurveyId] = useState<string | null>(null);
  const [surveyDetail, setSurveyDetail] = useState<ResidentSurveyDetailResponse | null>(null);
  const [detailLoading, setDetailLoading] = useState<boolean>(false);
  const [answers, setAnswers] = useState<Record<string, any>>({});
  const [validationErrors, setValidationErrors] = useState<Record<string, string>>({});
  const [isSubmitting, setIsSubmitting] = useState<boolean>(false);
  const [submitSuccess, setSubmitSuccess] = useState<boolean>(false);

  // Fetch active surveys
  const fetchSurveys = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await api.getResidentActiveSurveys();
      setSurveys(res.data || []);
    } catch (err: any) {
      setError(err?.message || 'Không thể tải danh sách cuộc khảo sát.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchSurveys();
  }, [fetchSurveys]);

  // Open survey for answering
  const handleOpenSurvey = async (surveyId: string) => {
    setActiveSurveyId(surveyId);
    setDetailLoading(true);
    setAnswers({});
    setValidationErrors({});
    setSubmitSuccess(false);
    try {
      const res = await api.getResidentSurveyDetails(surveyId);
      setSurveyDetail(res.data);
    } catch (err: any) {
      setError(err?.message || 'Không thể tải câu hỏi khảo sát.');
      setActiveSurveyId(null);
    } finally {
      setDetailLoading(false);
    }
  };

  const handleBackToList = () => {
    setActiveSurveyId(null);
    setSurveyDetail(null);
    setSubmitSuccess(false);
    fetchSurveys();
  };

  const handleAnswerChange = (questionId: string, value: any) => {
    setAnswers(prev => ({ ...prev, [questionId]: value }));
    if (validationErrors[questionId]) {
      setValidationErrors(prev => {
        const next = { ...prev };
        delete next[questionId];
        return next;
      });
    }
  };

  // Submit survey
  const handleSubmitSurvey = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!surveyDetail || !activeSurveyId) return;

    // Validate required questions
    const errors: Record<string, string> = {};
    for (const q of surveyDetail.questions) {
      if (q.is_required) {
        const val = answers[q.id];
        if (val === undefined || val === null || (typeof val === 'string' && val.trim() === '')) {
          errors[q.id] = 'Vui lòng hoàn thành câu hỏi bắt buộc này.';
        }
      }
    }

    if (Object.keys(errors).length > 0) {
      setValidationErrors(errors);
      return;
    }

    setIsSubmitting(true);
    try {
      await api.submitResidentSurvey(activeSurveyId, answers);
      setSubmitSuccess(true);
    } catch (err: any) {
      alert(err?.message || 'Gửi khảo sát thất bại. Vui lòng thử lại.');
    } finally {
      setIsSubmitting(false);
    }
  };

  // Compute progress percentage
  const totalQuestions = surveyDetail?.questions.length || 0;
  const answeredCount = surveyDetail?.questions.filter(q => {
    const val = answers[q.id];
    return val !== undefined && val !== null && (typeof val !== 'string' || val.trim() !== '');
  }).length || 0;
  const progressPercent = totalQuestions > 0 ? Math.round((answeredCount / totalQuestions) * 100) : 0;

  // Rating labels helper
  const getRatingLabel = (star: number) => {
    switch (star) {
      case 1: return 'Rất không hài lòng';
      case 2: return 'Không hài lòng';
      case 3: return 'Bình thường';
      case 4: return 'Hài lòng';
      case 5: return 'Rất hài lòng';
      default: return '';
    }
  };

  // VIEW 1: Survey Question Answering Screen
  if (activeSurveyId && surveyDetail) {
    if (detailLoading) {
      return (
        <div className="flex flex-col items-center justify-center p-12 space-y-4">
          <Loader2 className="w-8 h-8 animate-spin text-emerald-600" />
          <p className="text-sm font-medium text-slate-600">Đang tải biểu mẫu khảo sát...</p>
        </div>
      );
    }

    if (submitSuccess) {
      return (
        <div className="max-w-2xl mx-auto my-8 p-8 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm text-center space-y-6">
          <div className="w-16 h-16 bg-emerald-100 dark:bg-emerald-950/60 rounded-full flex items-center justify-center mx-auto text-emerald-600 dark:text-emerald-400">
            <CheckCircle2 className="w-10 h-10" />
          </div>
          <div className="space-y-2">
            <h2 className="text-2xl font-bold text-slate-900 dark:text-white">Cảm ơn bạn đã tham gia khảo sát!</h2>
            <p className="text-sm text-slate-600 dark:text-slate-400 max-w-md mx-auto">
              Ý kiến đóng góp chân thành của căn hộ bạn là cơ sở quan trọng để Ban Quản lý nâng cao chất lượng dịch vụ và không gian sống văn minh.
            </p>
          </div>
          <button
            onClick={handleBackToList}
            className="inline-flex items-center gap-2 px-6 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-medium text-sm rounded-xl transition-colors shadow-sm"
          >
            <ArrowLeft className="w-4 h-4" />
            Quay lại danh sách khảo sát
          </button>
        </div>
      );
    }

    if (surveyDetail.survey.has_submitted) {
      return (
        <div className="max-w-2xl mx-auto my-8 p-8 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm text-center space-y-6">
          <div className="w-16 h-16 bg-blue-100 dark:bg-blue-950/60 rounded-full flex items-center justify-center mx-auto text-blue-600 dark:text-blue-400">
            <CheckCircle2 className="w-10 h-10" />
          </div>
          <div className="space-y-2">
            <h2 className="text-2xl font-bold text-slate-900 dark:text-white">Căn hộ đã hoàn thành khảo sát này</h2>
            <p className="text-sm text-slate-600 dark:text-slate-400 max-w-md mx-auto">
              Phiếu đánh giá đã được hệ thống ghi nhận lúc <span className="font-semibold">{surveyDetail.survey.submitted_at || 'trước đây'}</span>. Theo quy định, mỗi căn hộ tham gia khảo sát một lần cho mỗi chiến dịch.
            </p>
          </div>
          <button
            onClick={handleBackToList}
            className="inline-flex items-center gap-2 px-6 py-2.5 bg-slate-800 hover:bg-slate-900 text-white font-medium text-sm rounded-xl transition-colors"
          >
            <ArrowLeft className="w-4 h-4" />
            Quay lại danh sách
          </button>
        </div>
      );
    }

    return (
      <div className="max-w-3xl mx-auto space-y-6">
        {/* Navigation & Header */}
        <button
          onClick={handleBackToList}
          className="inline-flex items-center gap-2 text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors"
        >
          <ArrowLeft className="w-4 h-4" /> Quay lại danh sách
        </button>

        <div className="bg-white dark:bg-slate-900 rounded-2xl p-6 sm:p-8 border border-slate-200 dark:border-slate-800 shadow-sm space-y-4">
          <div className="flex flex-wrap items-center gap-2">
            <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
              {surveyDetail.survey.target_block_name}
            </span>
            <span className="text-xs text-slate-500 font-mono">
              {surveyDetail.survey.survey_code}
            </span>
          </div>

          <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
            {surveyDetail.survey.title}
          </h1>

          {surveyDetail.survey.description && (
            <p className="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
              {surveyDetail.survey.description}
            </p>
          )}

          {/* Progress bar */}
          <div className="pt-2 border-t border-slate-100 dark:border-slate-800 space-y-2">
            <div className="flex justify-between text-xs font-medium text-slate-500 dark:text-slate-400">
              <span>Tiến độ hoàn thành: {answeredCount}/{totalQuestions} câu hỏi</span>
              <span>{progressPercent}%</span>
            </div>
            <div className="w-full h-2 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
              <div
                className="h-full bg-emerald-500 rounded-full transition-all duration-300"
                style={{ width: `${progressPercent}%` }}
              />
            </div>
          </div>
        </div>

        {/* Survey Form */}
        <form onSubmit={handleSubmitSurvey} className="space-y-6">
          {surveyDetail.questions.map((q, idx) => {
            const hasError = !!validationErrors[q.id];
            const currentVal = answers[q.id];

            return (
              <div
                key={q.id}
                className={`bg-white dark:bg-slate-900 rounded-2xl p-6 border transition-all ${
                  hasError
                    ? 'border-rose-400 dark:border-rose-800 ring-2 ring-rose-100 dark:ring-rose-950/50'
                    : 'border-slate-200 dark:border-slate-800 shadow-sm'
                }`}
              >
                <div className="space-y-3">
                  <div className="flex items-start justify-between gap-3">
                    <label className="text-base font-semibold text-slate-900 dark:text-white flex items-start gap-2">
                      <span className="flex-shrink-0 w-6 h-6 rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 text-xs font-bold flex items-center justify-center mt-0.5">
                        {idx + 1}
                      </span>
                      <span>
                        {q.question_text}{' '}
                        {q.is_required && <span className="text-rose-500 font-bold">*</span>}
                      </span>
                    </label>
                  </div>

                  {/* Render Question Input according to Type */}
                  {q.question_type === 'RATING_1_TO_5' && (
                    <div className="space-y-2 pt-2">
                      <div className="flex items-center gap-2 sm:gap-3">
                        {[1, 2, 3, 4, 5].map((star) => {
                          const isSelected = currentVal >= star;
                          return (
                            <button
                              key={star}
                              type="button"
                              onClick={() => handleAnswerChange(q.id, star)}
                              className={`p-2 sm:p-2.5 rounded-xl border transition-all flex flex-col items-center gap-1 focus:outline-none focus:ring-2 focus:ring-emerald-500 ${
                                currentVal === star
                                  ? 'border-amber-400 bg-amber-50 dark:bg-amber-950/40 text-amber-500 scale-105 shadow-sm'
                                  : isSelected
                                  ? 'border-amber-200 text-amber-400 hover:border-amber-300'
                                  : 'border-slate-200 dark:border-slate-700 text-slate-300 dark:text-slate-600 hover:border-slate-300'
                              }`}
                            >
                              <Star className={`w-6 h-6 sm:w-7 sm:h-7 ${isSelected ? 'fill-current' : ''}`} />
                              <span className="text-xs font-bold">{star}</span>
                            </button>
                          );
                        })}
                      </div>
                      {currentVal && (
                        <p className="text-xs font-medium text-amber-600 dark:text-amber-400">
                          {getRatingLabel(currentVal)}
                        </p>
                      )}
                    </div>
                  )}

                  {q.question_type === 'YES_NO' && (
                    <div className="grid grid-cols-2 gap-3 pt-2 max-w-sm">
                      {['Có', 'Không'].map((option) => (
                        <button
                          key={option}
                          type="button"
                          onClick={() => handleAnswerChange(q.id, option)}
                          className={`px-4 py-3 rounded-xl border font-medium text-sm transition-all text-center focus:outline-none focus:ring-2 focus:ring-emerald-500 ${
                            currentVal === option
                              ? 'border-emerald-600 bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 shadow-sm font-semibold'
                              : 'border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800'
                          }`}
                        >
                          {option}
                        </button>
                      ))}
                    </div>
                  )}

                  {q.question_type === 'MULTIPLE_CHOICE' && (
                    <div className="space-y-2 pt-2">
                      {(q.options || q.options_json || []).map((opt) => (
                        <label
                          key={opt}
                          className={`flex items-center gap-3 p-3.5 rounded-xl border cursor-pointer transition-all ${
                            currentVal === opt
                              ? 'border-emerald-600 bg-emerald-50/70 dark:bg-emerald-950/40 text-emerald-900 dark:text-emerald-200 font-medium'
                              : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800/60 text-slate-700 dark:text-slate-300'
                          }`}
                        >
                          <input
                            type="radio"
                            name={`q_${q.id}`}
                            value={opt}
                            checked={currentVal === opt}
                            onChange={() => handleAnswerChange(q.id, opt)}
                            className="w-4 h-4 text-emerald-600 focus:ring-emerald-500"
                          />
                          <span className="text-sm">{opt}</span>
                        </label>
                      ))}
                    </div>
                  )}

                  {q.question_type === 'FREE_TEXT' && (
                    <div className="space-y-1 pt-2">
                      <textarea
                        value={currentVal || ''}
                        onChange={(e) => handleAnswerChange(q.id, e.target.value)}
                        placeholder="Nhập ý kiến đóng góp của bạn (tối đa 1000 ký tự)..."
                        maxLength={1000}
                        rows={4}
                        className="w-full px-4 py-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white placeholder-slate-400 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 resize-none"
                      />
                      <div className="text-right text-xs text-slate-400">
                        {(currentVal || '').length} / 1000 ký tự
                      </div>
                    </div>
                  )}

                  {/* Inline Error */}
                  {hasError && (
                    <div className="flex items-center gap-1.5 text-xs text-rose-600 dark:text-rose-400 font-medium pt-1">
                      <AlertCircle className="w-3.5 h-3.5" />
                      <span>{validationErrors[q.id]}</span>
                    </div>
                  )}
                </div>
              </div>
            );
          })}

          {/* Submit Actions */}
          <div className="flex items-center justify-end gap-3 pt-4">
            <button
              type="button"
              onClick={handleBackToList}
              disabled={isSubmitting}
              className="px-5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-300 text-sm font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
            >
              Hủy bỏ
            </button>
            <button
              type="submit"
              disabled={isSubmitting}
              className="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white text-sm font-semibold shadow-sm transition-all hover:shadow"
            >
              {isSubmitting ? (
                <>
                  <Loader2 className="w-4 h-4 animate-spin" />
                  Đang ghi nhận phiếu...
                </>
              ) : (
                <>
                  <Send className="w-4 h-4" />
                  Gửi phiếu đánh giá
                </>
              )}
            </button>
          </div>
        </form>
      </div>
    );
  }

  // VIEW 2: Active Surveys List
  return (
    <div className="space-y-6">
      {/* Intro Header */}
      <div className="bg-gradient-to-r from-emerald-600 via-teal-600 to-cyan-700 rounded-2xl p-6 sm:p-8 text-white shadow-sm space-y-3">
        <div className="inline-flex items-center gap-2 px-3 py-1 bg-white/20 backdrop-blur-md rounded-full text-xs font-semibold">
          <Sparkles className="w-3.5 h-3.5" />
          Khảo sát Ý kiến & Mức độ Hài lòng (CSAT)
        </div>
        <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight">
          Cùng chung tay nâng tầm chất lượng sống
        </h1>
        <p className="text-emerald-50 text-sm sm:text-base max-w-2xl leading-relaxed">
          Mỗi đánh giá của bạn là thông tin quý báu để Ban Quản lý đo lường chỉ số hài lòng CSAT, cải tiến dịch vụ kỹ thuật, vệ sinh, và an ninh của tòa nhà.
        </p>
      </div>

      {/* Error state */}
      {error && (
        <div className="p-4 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900 rounded-xl text-rose-700 dark:text-rose-300 text-sm flex items-center gap-2">
          <AlertCircle className="w-5 h-5 flex-shrink-0" />
          <span>{error}</span>
        </div>
      )}

      {/* Loading state */}
      {loading ? (
        <div className="flex flex-col items-center justify-center p-12 space-y-3">
          <Loader2 className="w-8 h-8 animate-spin text-emerald-600" />
          <p className="text-sm text-slate-500 font-medium">Đang tải danh sách khảo sát...</p>
        </div>
      ) : surveys.length === 0 ? (
        <div className="p-12 text-center bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 space-y-3">
          <ClipboardCheck className="w-12 h-12 text-slate-400 mx-auto" />
          <h3 className="text-lg font-semibold text-slate-800 dark:text-slate-200">
            Hiện tại chưa có cuộc khảo sát nào dành cho căn hộ của bạn
          </h3>
          <p className="text-sm text-slate-500 max-w-md mx-auto">
            Khi Ban Quản lý phát động chiến dịch khảo sát định kỳ, thông báo sẽ hiển thị tại đây.
          </p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
          {surveys.map((survey) => (
            <div
              key={survey.id}
              className="bg-white dark:bg-slate-900 rounded-2xl p-6 border border-slate-200 dark:border-slate-800 shadow-sm hover:shadow-md transition-all flex flex-col justify-between space-y-5"
            >
              <div className="space-y-3">
                <div className="flex items-center justify-between gap-2">
                  <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800">
                    {survey.target_block_name}
                  </span>
                  {survey.has_submitted ? (
                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300">
                      <CheckCircle2 className="w-3.5 h-3.5" />
                      Đã hoàn thành
                    </span>
                  ) : (
                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300">
                      <Clock className="w-3.5 h-3.5" />
                      Còn {survey.days_left} ngày
                    </span>
                  )}
                </div>

                <h3 className="text-lg font-bold text-slate-900 dark:text-white line-clamp-2">
                  {survey.title}
                </h3>

                {survey.description && (
                  <p className="text-xs text-slate-500 dark:text-slate-400 line-clamp-3 leading-relaxed">
                    {survey.description}
                  </p>
                )}

                <div className="pt-2 flex flex-wrap gap-4 text-xs text-slate-500 dark:text-slate-400 border-t border-slate-100 dark:border-slate-800">
                  <span className="flex items-center gap-1.5">
                    <Calendar className="w-4 h-4 text-slate-400" />
                    Hạn: {survey.end_date}
                  </span>
                  <span className="flex items-center gap-1.5">
                    <ClipboardCheck className="w-4 h-4 text-slate-400" />
                    {survey.total_questions} câu hỏi
                  </span>
                </div>
              </div>

              <div>
                <button
                  onClick={() => handleOpenSurvey(survey.id)}
                  className={`w-full py-2.5 px-4 rounded-xl text-sm font-semibold transition-all flex items-center justify-center gap-2 ${
                    survey.has_submitted
                      ? 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700'
                      : 'bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm hover:shadow'
                  }`}
                >
                  {survey.has_submitted ? (
                    <>
                      <span>Xem lại phiếu đã gửi</span>
                      <ChevronRight className="w-4 h-4" />
                    </>
                  ) : (
                    <>
                      <span>Bắt đầu khảo sát</span>
                      <ChevronRight className="w-4 h-4" />
                    </>
                  )}
                </button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
};
