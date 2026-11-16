import React, { Component, ErrorInfo, ReactNode } from 'react';

interface Props {
  children: ReactNode;
  fallback?: ReactNode;
}

interface State {
  hasError: boolean;
  error?: Error;
  errorInfo?: ErrorInfo;
  isChunkError: boolean;
  showDetails: boolean;
}

export class ChunkErrorBoundary extends Component<Props, State> {
  public state: State = {
    hasError: false,
    isChunkError: false,
    showDetails: false,
  };

  public static getDerivedStateFromError(error: Error): Partial<State> {
    const chunkFailedMessages = [
      'Failed to fetch dynamically imported module',
      'Importing a module script failed',
      'error loading dynamically imported module',
      'Loading chunk',
      'chunk load failed',
    ];

    const isChunk = chunkFailedMessages.some((msg) =>
      error?.message?.toLowerCase().includes(msg.toLowerCase())
    );

    return { hasError: true, error, isChunkError: isChunk };
  }

  public componentDidCatch(error: Error, errorInfo: ErrorInfo) {
    console.error('Unhandled UI/Chunk error caught by ChunkErrorBoundary:', error, errorInfo);
    this.setState({ errorInfo });

    // Phát hiện lỗi tải file chunk (thường xảy ra khi cập nhật phiên bản mới)
    const chunkFailedMessages = [
      'Failed to fetch dynamically imported module',
      'Importing a module script failed',
      'error loading dynamically imported module',
      'Loading chunk',
      'chunk load failed',
    ];

    const isChunkError = chunkFailedMessages.some((msg) =>
      error?.message?.toLowerCase().includes(msg.toLowerCase())
    );

    if (isChunkError) {
      const hasRetried = sessionStorage.getItem('smartcassavas_chunk_retry');
      if (!hasRetried) {
        sessionStorage.setItem('smartcassavas_chunk_retry', 'true');
        // Tự động reload để lấy bundle/assets mới nhất
        window.location.reload();
        return;
      }
    }
  }

  private handleManualReload = () => {
    sessionStorage.removeItem('smartcassavas_chunk_retry');
    window.location.reload();
  };

  private handleClearCacheReload = () => {
    try {
      sessionStorage.clear();
      const keysToRemove: string[] = [];
      for (let i = 0; i < localStorage.length; i++) {
        const k = localStorage.key(i);
        if (k && (k.startsWith('smartcassavas_') || k.includes('_cache'))) {
          keysToRemove.push(k);
        }
      }
      keysToRemove.forEach((k) => localStorage.removeItem(k));
    } catch {
      // ignore
    }
    window.location.reload();
  };

  private handleGoHome = () => {
    sessionStorage.removeItem('smartcassavas_chunk_retry');
    window.location.href = '/home';
  };

  public render() {
    if (this.state.hasError) {
      if (this.props.fallback) {
        return this.props.fallback;
      }

      const { isChunkError, error, showDetails } = this.state;

      return (
        <div className="min-h-screen w-full flex items-center justify-center bg-slate-900 px-4 py-12 text-slate-100">
          <div className="w-full max-w-lg bg-slate-800/90 border border-slate-700/80 rounded-2xl p-6 sm:p-8 shadow-2xl backdrop-blur-md text-center">
            <div className="w-14 h-14 bg-red-500/10 border border-red-500/20 text-red-400 rounded-2xl flex items-center justify-center mx-auto mb-4">
              <svg
                className="w-7 h-7"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                strokeWidth="2"
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                />
              </svg>
            </div>

            <h3 className="text-xl font-bold text-white mb-2">
              {isChunkError ? 'Không thể tải tài nguyên giao diện' : 'Đã xảy ra lỗi giao diện'}
            </h3>
            <p className="text-sm text-slate-400 mb-6 leading-relaxed">
              {isChunkError
                ? 'Hệ thống có thể vừa được cập nhật phiên bản mới hoặc kết nối mạng bị gián đoạn. Vui lòng tải lại trang để tiếp tục.'
                : 'Ứng dụng gặp sự cố khi hiển thị dữ liệu. Vui lòng tải lại trang hoặc làm mới bộ nhớ đệm để tiếp tục.'}
            </p>

            {error && (
              <div className="mb-6 text-left">
                <button
                  type="button"
                  onClick={() => this.setState({ showDetails: !showDetails })}
                  className="text-xs text-sky-400 hover:text-sky-300 underline font-mono flex items-center gap-1 mx-auto"
                >
                  <span>{showDetails ? 'Ẩn chi tiết kỹ thuật' : 'Xem chi tiết kỹ thuật'}</span>
                </button>

                {showDetails && (
                  <div className="mt-3 p-3 bg-slate-950/80 border border-slate-700 rounded-xl text-xs font-mono text-red-300 max-h-48 overflow-y-auto break-all leading-normal">
                    <p className="font-bold text-red-400 mb-1">{error.name}: {error.message}</p>
                    {error.stack && (
                      <pre className="text-[10px] text-slate-400 whitespace-pre-wrap">{error.stack}</pre>
                    )}
                  </div>
                )}
              </div>
            )}

            <div className="flex flex-col sm:flex-row gap-2.5 justify-center">
              <button
                type="button"
                onClick={this.handleManualReload}
                className="px-4 py-2.5 bg-blue-600 hover:bg-blue-500 active:scale-95 text-white font-medium rounded-xl text-sm transition shadow-lg shadow-blue-600/30 flex items-center justify-center gap-2 cursor-pointer"
              >
                <svg
                  className="w-4 h-4"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                  strokeWidth="2"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                  />
                </svg>
                Tải lại trang
              </button>

              <button
                type="button"
                onClick={this.handleClearCacheReload}
                className="px-4 py-2.5 bg-amber-600 hover:bg-amber-500 active:scale-95 text-white font-medium rounded-xl text-sm transition shadow-lg shadow-amber-600/30 flex items-center justify-center gap-2 cursor-pointer"
                title="Xóa bộ nhớ đệm dữ liệu cũ và tải lại"
              >
                Xóa bộ nhớ đệm & Tải lại
              </button>

              <button
                type="button"
                onClick={this.handleGoHome}
                className="px-4 py-2.5 bg-slate-700 hover:bg-slate-600 active:scale-95 text-slate-200 font-medium rounded-xl text-sm transition cursor-pointer"
              >
                Về trang chủ
              </button>
            </div>
          </div>
        </div>
      );
    }

    return this.props.children;
  }
}

export default ChunkErrorBoundary;
