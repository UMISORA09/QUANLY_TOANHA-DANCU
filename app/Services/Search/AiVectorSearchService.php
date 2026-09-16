<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\DB;

class AiVectorSearchService
{
    /**
     * Tìm kiếm lai (Hybrid Search) kết hợp Full-Text / Keyword và Vector Similarity
     * phục vụ AI Assistant tra cứu tài liệu tòa nhà & quy chế dân cư.
     *
     * @param  string  $query  Câu hỏi hoặc từ khóa từ cư dân / AI Assistant
     * @param  array<float>|null  $queryVector  Vector embedding của câu hỏi (nếu có)
     * @param  string|null  $buildingId  Scope tòa nhà (block_id) để bảo vệ phân quyền
     * @param  array<string>  $allowedCategories  Danh mục được phép tra cứu
     * @param  int  $limit  Số chunks trả về cho AI Assistant context
     * @return array<int, array{chunk_id: string, document_id: string, document_title: string, content: string, score: float, category: string, block_id: ?string}>
     */
    public function hybridSearch(
        string $query,
        ?array $queryVector = null,
        ?string $buildingId = null,
        array $allowedCategories = [],
        int $limit = 5
    ): array {
        $startTime = microtime(true);
        $cleanQuery = trim($query);

        // Kiểm tra xem bảng ai_knowledge_documents & ai_knowledge_chunks có tồn tại trong CSDL không
        if (! DB::getSchemaBuilder()->hasTable('ai_knowledge_chunks')
            || ! DB::getSchemaBuilder()->hasTable('ai_knowledge_documents')
        ) {
            return [];
        }

        $qb = DB::table('ai_knowledge_chunks')
            ->join('ai_knowledge_documents', 'ai_knowledge_chunks.document_id', '=', 'ai_knowledge_documents.id');

        // Phân quyền & Scope Tòa nhà (Security / Multi-tenancy Isolation)
        // Cột thực tế trong CSDL là `block_id`, không phải `building_id`
        if (! empty($buildingId)) {
            $qb->where(function ($q) use ($buildingId) {
                $q->where('ai_knowledge_documents.block_id', $buildingId)
                    ->orWhereNull('ai_knowledge_documents.block_id'); // Tài liệu chung toàn khu
            });
        }

        if (! empty($allowedCategories)) {
            $qb->whereIn('ai_knowledge_documents.category', $allowedCategories);
        }

        // 1. Keyword / Full-text Score (BM25 Approximation)
        // Cột thực tế trong CSDL là `doc_title`, không phải `title`
        if ($cleanQuery !== '') {
            $searchTerm = '%'.$cleanQuery.'%';
            $qb->where(function ($q) use ($searchTerm) {
                $q->where('ai_knowledge_chunks.chunk_content', 'like', $searchTerm)
                    ->orWhere('ai_knowledge_documents.doc_title', 'like', $searchTerm);
            });
        }

        $qb->select(
            'ai_knowledge_chunks.id as chunk_id',
            'ai_knowledge_chunks.document_id',
            'ai_knowledge_documents.doc_title as document_title',
            'ai_knowledge_chunks.chunk_content as content',
            'ai_knowledge_documents.category',
            'ai_knowledge_documents.block_id',
            'ai_knowledge_chunks.metadata'
        );

        $results = $qb->take(50)->get();

        if ($results->isEmpty()) {
            return [];
        }

        // 2. Vector Cosine Similarity & Hybrid Scoring
        $scored = [];
        $weightKeyword = config('search.ai_knowledge.hybrid_weight_keyword', 0.4);
        $weightVector = config('search.ai_knowledge.hybrid_weight_vector', 0.6);

        foreach ($results as $item) {
            $keywordScore = 0.5;
            // Tính toán mức độ khớp từ khóa
            if ($cleanQuery !== '' && stripos($item->content, $cleanQuery) !== false) {
                $keywordScore = 1.0;
            }

            // Vector scoring: thử trích xuất embedding từ metadata JSON nếu có
            $vectorScore = 0.5;
            if ($queryVector !== null && ! empty($item->metadata)) {
                $metaData = is_string($item->metadata) ? json_decode($item->metadata, true) : $item->metadata;
                if (is_array($metaData) && ! empty($metaData['embedding'])) {
                    $docVector = $metaData['embedding'];
                    if (is_array($docVector)) {
                        $vectorScore = $this->cosineSimilarity($queryVector, $docVector);
                    }
                }
            }

            $hybridScore = ($keywordScore * $weightKeyword) + ($vectorScore * $weightVector);

            $scored[] = [
                'chunk_id' => $item->chunk_id,
                'document_id' => $item->document_id,
                'document_title' => $item->document_title,
                'content' => $item->content,
                'score' => round($hybridScore, 4),
                'category' => $item->category,
                'block_id' => $item->block_id,
            ];
        }

        // Sắp xếp giảm dần theo hybrid score
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Tính Cosine Similarity giữa hai vector embeddings
     *
     * @param  array<float>  $vecA
     * @param  array<float>  $vecB
     */
    public function cosineSimilarity(array $vecA, array $vecB): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $len = min(count($vecA), count($vecB));

        if ($len === 0) {
            return 0.0;
        }

        for ($i = 0; $i < $len; $i++) {
            $dotProduct += $vecA[$i] * $vecB[$i];
            $normA += $vecA[$i] * $vecA[$i];
            $normB += $vecB[$i] * $vecB[$i];
        }

        $denominator = sqrt($normA) * sqrt($normB);

        return $denominator > 0.0 ? ($dotProduct / $denominator) : 0.0;
    }
}
