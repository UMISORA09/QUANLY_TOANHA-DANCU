<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, string|array<string>> */
    public function rules(): array
    {
        return [
            'id' => $this->isMethod('POST') && ! $this->route('id') ? 'required|uuid' : 'prohibited',
            'version' => $this->route('id') ? 'required|integer|min:1' : 'prohibited',
            'title' => 'required|string|max:255',
            'summary_excerpt' => 'nullable|string|max:500',
            'content_html' => 'required|string|max:250000',
            'category' => 'required|in:GENERAL,EMERGENCY,MAINTENANCE,FEES,COMMUNITY_EVENT',
            'target_scope' => 'required|in:ALL_BLOCKS,BLOCK,FLOOR',
            'target_block_id' => 'required_unless:target_scope,ALL_BLOCKS|nullable|uuid',
            'target_floor_id' => 'required_if:target_scope,FLOOR|nullable|uuid',
            'is_pinned' => 'required|boolean',
            'is_urgent' => 'required|boolean',
            'publish_at' => 'nullable|date',
            'expires_at' => 'nullable|date',
            'uploaded_attachment_tokens' => 'sometimes|array|max:5',
            'uploaded_attachment_tokens.*' => 'required|string|max:4096|distinct',
            'attachments' => 'sometimes|array|max:5',
            'attachments.*' => 'required|file|max:5120|mimetypes:image/jpeg,image/png,image/webp,application/pdf',
            'remove_attachment_ids' => 'sometimes|array|max:5',
            'remove_attachment_ids.*' => 'required|uuid|distinct',
            'status' => 'prohibited',
            'author_user_id' => 'prohibited',
            'requires_acknowledgement' => 'prohibited',
        ];
    }
}
