<?php

namespace App\Services\Search\Contracts;

use App\Services\Search\DTOs\SearchResult;

interface SearchDriverInterface
{
    /**
     * Thực hiện tìm kiếm Full-Text kết hợp Fuzzy và Filter
     *
     * @param  string  $index  Tên index hoặc bảng (VD: 'amenities', 'residents')
     * @param  string  $query  Từ khóa tìm kiếm
     * @param  array<string, mixed>  $filters  Bộ lọc bổ sung (VD: ['block_id' => '...', 'is_active' => true])
     * @param  array<string, mixed>  $options  Tùy chọn: page, per_page, sort, fuzzy
     */
    public function search(string $index, string $query, array $filters = [], array $options = []): SearchResult;

    /**
     * Gợi ý tìm kiếm nhanh (Autocomplete / Typeahead)
     *
     * @param  string  $index  Tên index/bảng
     * @param  string  $prefix  Tiền tố người dùng đang nhập
     * @param  int  $limit  Số lượng gợi ý tối đa
     * @return array<int, array{id: string, label: string, code?: string, extra?: mixed}>
     */
    public function suggest(string $index, string $prefix, int $limit = 5): array;

    /**
     * Đồng bộ thêm/cập nhật một tài liệu vào Search Index
     *
     * @param  array<string, mixed>  $document
     */
    public function index(string $index, string $id, array $document): void;

    /**
     * Xóa một tài liệu khỏi Search Index
     */
    public function delete(string $index, string $id): void;

    /**
     * Xóa toàn bộ dữ liệu trong Search Index
     */
    public function flush(string $index): void;
}
