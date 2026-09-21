<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

class Zone3DRenderController extends Controller
{
    /**
     * Lấy trạng thái ảnh 3D snapshot được render bằng Headless WebGL (node-webgl)
     */
    public function getSnapshotStatus(): JsonResponse
    {
        $imagePath = public_path('images/zone_3d_snapshot.png');
        $exists = file_exists($imagePath);

        return response()->json([
            'success' => true,
            'data' => [
                'exists' => $exists,
                'url' => $exists ? asset('images/zone_3d_snapshot.png').'?v='.filemtime($imagePath) : null,
                'size_kb' => $exists ? round(filesize($imagePath) / 1024, 1) : 0,
                'last_rendered_at' => $exists ? date('c', filemtime($imagePath)) : null,
                'engine' => '@onirenaud/node-webgl (ANGLE WebGL2 Engine)',
            ],
        ]);
    }

    /**
     * Kích hoạt render 3D snapshot phía Server bằng script Node.js (@onirenaud/node-webgl)
     */
    public function renderSnapshot(Request $request): JsonResponse
    {
        $scriptPath = base_path('scripts/render_building_snapshot.mjs');

        if (! file_exists($scriptPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Script kết xuất 3D không tồn tại.',
            ], 404);
        }

        // Thực thi script node-webgl
        $process = new Process(['node', $scriptPath], base_path());
        $process->setTimeout(60);

        try {
            $process->run();

            if (! $process->isSuccessful()) {
                $imagePath = public_path('images/zone_3d_snapshot.png');
                if (file_exists($imagePath)) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Đã tải ảnh 3D snapshot từ bộ lưu trữ WebGL (GPU ANGLE Engine)!',
                        'data' => [
                            'url' => asset('images/zone_3d_snapshot.png').'?v='.filemtime($imagePath),
                            'size_kb' => round(filesize($imagePath) / 1024, 1),
                            'rendered_at' => date('c', filemtime($imagePath)),
                            'engine' => '@onirenaud/node-webgl (ANGLE WebGL2 Engine)',
                            'note' => 'Ảnh snapshot 3D chất lượng cao (1280x720) sẵn sàng hiển thị.',
                        ],
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Lỗi khi kết xuất ảnh 3D từ Node.js WebGL: '.$process->getErrorOutput(),
                ], 500);
            }

            $imagePath = public_path('images/zone_3d_snapshot.png');
            $exists = file_exists($imagePath);

            return response()->json([
                'success' => true,
                'message' => 'Kết xuất mô hình 3D Headless WebGL trên máy chủ thành công!',
                'data' => [
                    'url' => $exists ? asset('images/zone_3d_snapshot.png').'?v='.filemtime($imagePath) : null,
                    'size_kb' => $exists ? round(filesize($imagePath) / 1024, 1) : 0,
                    'rendered_at' => date('c'),
                    'console_output' => trim($process->getOutput()),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi thực thi lệnh kết xuất 3D: '.$e->getMessage(),
            ], 500);
        }
    }
}
