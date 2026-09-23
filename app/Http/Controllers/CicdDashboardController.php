<?php

namespace App\Http\Controllers;

use App\Services\Cicd\GitHubActionsService;
use App\Services\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CicdDashboardController extends Controller
{
    public function __construct(
        protected GitHubActionsService $cicdService
    ) {}

    /**
     * Bundle toàn bộ dữ liệu Dashboard trong 1 request (Tối ưu tốc độ cao)
     */
    public function bundle(Request $request): JsonResponse
    {
        $start = microtime(true);
        $filters = [
            'status' => $request->query('status'),
            'branch' => $request->query('branch'),
            'workflow' => $request->query('workflow'),
            'search' => $request->query('search'),
        ];

        $data = $this->cicdService->getDashboardBundle($filters, $request->boolean('force'));
        $elapsed = round((microtime(true) - $start) * 1000, 2);

        return response()->json([
            'success' => true,
            'data' => $data,
            'time_ms' => $elapsed,
        ]);
    }

    /**
     * Tổng quan thống kê CI/CD
     */
    public function overview(Request $request): JsonResponse
    {
        $overview = $this->cicdService->getOverview();

        return response()->json([
            'success' => true,
            'data' => $overview,
        ]);
    }

    /**
     * Danh sách pipelines với filters
     */
    public function pipelines(Request $request): JsonResponse
    {
        $filters = [
            'status' => $request->query('status'),
            'branch' => $request->query('branch'),
            'workflow' => $request->query('workflow'),
            'search' => $request->query('search'),
        ];

        $pipelines = $this->cicdService->getPipelines($filters);

        return response()->json([
            'success' => true,
            'data' => $pipelines,
            'total' => count($pipelines),
        ]);
    }

    /**
     * Danh sách Git branches thực tế
     */
    public function branches(): JsonResponse
    {
        $branches = $this->cicdService->getGitBranches();

        return response()->json([
            'success' => true,
            'data' => $branches,
        ]);
    }

    /**
     * Chi tiết một pipeline
     */
    public function pipelineDetail(string $id): JsonResponse
    {
        $pipeline = $this->cicdService->getPipelineDetail($id);
        if (! $pipeline) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy pipeline.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $pipeline,
        ]);
    }

    /**
     * Danh sách Jobs & Steps của pipeline
     */
    public function jobs(string $id): JsonResponse
    {
        $jobs = $this->cicdService->getPipelineJobs($id);

        return response()->json([
            'success' => true,
            'data' => $jobs,
        ]);
    }

    /**
     * Logs của pipeline / job
     */
    public function logs(string $id, Request $request): JsonResponse
    {
        $jobId = $request->query('job_id');
        $logs = $this->cicdService->getLogs($id, $jobId);

        return response()->json([
            'success' => true,
            'data' => [
                'pipeline_id' => $id,
                'job_id' => $jobId,
                'logs' => $logs,
            ],
        ]);
    }

    /**
     * Danh sách triển khai (Deployments)
     */
    public function deployments(): JsonResponse
    {
        $deployments = $this->cicdService->getDeployments();

        return response()->json([
            'success' => true,
            'data' => $deployments,
        ]);
    }

    /**
     * Thông tin các môi trường (Environments)
     */
    public function environments(): JsonResponse
    {
        $environments = $this->cicdService->getEnvironments();

        return response()->json([
            'success' => true,
            'data' => $environments,
        ]);
    }

    /**
     * Trạng thái sức khỏe hệ thống (System Health)
     */
    public function health(): JsonResponse
    {
        $health = $this->cicdService->getSystemHealth();

        return response()->json([
            'success' => true,
            'data' => $health,
        ]);
    }

    /**
     * Lịch sử hoạt động gần đây
     */
    public function activities(Request $request): JsonResponse
    {
        $activities = $this->cicdService->getRecentActivities(null, $request->boolean('force'));

        return response()->json([
            'success' => true,
            'data' => $activities,
        ]);
    }

    /**
     * Kích hoạt chạy pipeline mới (Run Pipeline)
     */
    public function runPipeline(Request $request): JsonResponse
    {
        $user = RbacService::resolveUser($request);
        $userRole = $request->header('X-User-Role') ?? 'admin';

        if ($user && ! RbacService::hasPermission($user, 'CICD:RUN') && $userRole !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền kích hoạt chạy pipeline (Yêu cầu quyền CICD:RUN hoặc Admin).',
            ], 403);
        }

        $validated = $request->validate([
            'workflow' => 'required|string',
            'branch' => 'nullable|string',
            'environment' => 'nullable|string',
        ]);

        $result = $this->cicdService->triggerWorkflow(
            $validated['workflow'],
            $validated['branch'] ?? 'main',
            ['environment' => $validated['environment'] ?? 'development']
        );

        return response()->json($result);
    }

    /**
     * Chạy lại pipeline
     */
    public function retryPipeline(string $id, Request $request): JsonResponse
    {
        $result = $this->cicdService->retryWorkflow($id);

        return response()->json($result);
    }

    /**
     * Hủy pipeline
     */
    public function cancelPipeline(string $id, Request $request): JsonResponse
    {
        $result = $this->cicdService->cancelWorkflow($id);

        return response()->json($result);
    }

    /**
     * Kích hoạt deploy
     */
    public function deploy(Request $request): JsonResponse
    {
        $user = RbacService::resolveUser($request);
        $userRole = $request->header('X-User-Role') ?? 'admin';

        if ($user && ! RbacService::hasPermission($user, 'CICD:DEPLOY') && $userRole !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền thực thi lệnh Deployment.',
            ], 403);
        }

        $validated = $request->validate([
            'environment' => 'required|string|in:staging,production,development',
            'image_tag' => 'nullable|string',
        ]);

        $result = $this->cicdService->deploy($validated['environment'], $validated['image_tag'] ?? null);

        return response()->json($result);
    }

    /**
     * Kích hoạt rollback
     */
    public function rollback(Request $request): JsonResponse
    {
        $user = RbacService::resolveUser($request);
        $userRole = $request->header('X-User-Role') ?? 'admin';

        if ($user && ! RbacService::hasPermission($user, 'CICD:ROLLBACK') && $userRole !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền kích hoạt Rollback hệ thống.',
            ], 403);
        }

        $validated = $request->validate([
            'environment' => 'required|string|in:staging,production',
            'target_version' => 'required|string',
        ]);

        $result = $this->cicdService->rollback($validated['environment'], $validated['target_version']);

        return response()->json($result);
    }
}
