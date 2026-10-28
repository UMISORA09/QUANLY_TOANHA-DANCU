<?php

namespace App\Services;

use App\Models\Suggestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SuggestionNotificationService
{
    public function notifyManagersOnNewSuggestion(Suggestion $suggestion): void
    {
        $suggestion->loadMissing(['box', 'apartment']);
        $box = $suggestion->box;
        $boxBlockId = $box?->block_id;

        // Find eligible managers
        $managers = User::where('status', 'ACTIVE')
            ->whereHas('roles', function ($roleQuery): void {
                $roleQuery->whereIn('role_code', ['SUPER_ADMIN', 'SUPER_ADMI', 'BUILDING_MANAGER', 'ADMIN'])
                    ->orWhereHas('permissions', function ($permQuery): void {
                        $permQuery->where('permission_code', 'SUGGESTION:VIEW');
                    });
            })
            ->get();

        $boxName = $box?->box_name ?? 'Hòm thư góp ý';

        if ($suggestion->is_anonymous) {
            $title = 'Góp ý ẩn danh mới · '.$boxName;
            $body = 'Có ý kiến đóng góp ẩn danh về chủ đề "'.$suggestion->topic_category.'": '.$suggestion->title;
        } else {
            $aptNum = $suggestion->apartment?->apartment_number ? 'Căn hộ '.$suggestion->apartment->apartment_number.' - ' : '';
            $title = 'Góp ý mới từ cư dân · '.$boxName;
            $body = $aptNum.'Góp ý về "'.$suggestion->topic_category.'": '.$suggestion->title;
        }

        $deepLink = '/quan-ly?tab=suggestions&suggestion_id='.$suggestion->id;
        $accessService = app(SuggestionAccessService::class);

        foreach ($managers as $manager) {
            $assignedBlock = $accessService->assignedBlock($manager);

            // Block scope isolation check
            if ($assignedBlock && $boxBlockId && $assignedBlock !== $boxBlockId) {
                continue;
            }

            DB::table('user_in_app_notifications')->insert([
                'id' => (string) Str::uuid(),
                'recipient_user_id' => $manager->id,
                'title' => Str::limit($title, 250),
                'body_message' => Str::limit($body, 1000),
                'deep_link_url' => $deepLink,
                'category' => 'SUGGESTION',
                'is_read' => 0,
                'created_at' => now(),
            ]);
        }
    }

    public function notifyResidentOnOfficialReply(Suggestion $suggestion): void
    {
        // Only notify if suggestion is not anonymous and resident_user_id is present
        if ($suggestion->is_anonymous || ! $suggestion->resident_user_id) {
            return;
        }

        $title = 'Ban Quản lý đã phản hồi góp ý của bạn';
        $body = 'Góp ý "'.$suggestion->title.'" đã nhận được phản hồi chính thức từ Ban Quản lý.';
        $deepLink = '/cu-dan?tab=feedback&suggestion_id='.$suggestion->id;

        DB::table('user_in_app_notifications')->insert([
            'id' => (string) Str::uuid(),
            'recipient_user_id' => $suggestion->resident_user_id,
            'title' => Str::limit($title, 250),
            'body_message' => Str::limit($body, 1000),
            'deep_link_url' => $deepLink,
            'category' => 'SUGGESTION',
            'is_read' => 0,
            'created_at' => now(),
        ]);
    }
}
