<?php

namespace App\Services\Search\Drivers;

use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\DTOs\SearchResult;
use App\Services\Search\VietnameseNormalizer;
use App\Services\Search\VietnameseSpellCorrector;
use Illuminate\Support\Facades\DB;

/**
 * Smart Search Driver for Smart Apartment Management
 *
 * Tính năng chính:
 * 1. Chuẩn hóa Query toàn diện: Unicode NFC -> Lowercase -> Dấu câu -> Khoảng trắng -> Không dấu.
 * 2. Sửa lỗi chính tả & từ đồng âm (Spell Correction với từ điển ~74K từ Viet74K):
 *    - ku <-> khu, duon <-> duong, gim <-> gym, tenit <-> tennis, bbq <-> nuong, toa <-> thap.
 *    - Khoảng cách Levenshtein <= 2 & similar_text >= 75%.
 *    - Bảo vệ các từ không có nghĩa (ví dụ xyzabc) không bị sửa bừa bãi.
 * 3. Tìm kiếm cụm từ & đa từ (Phrase & Multi-token match):
 *    - "ku nghi duon" -> "khu nghi duong" -> "Khu Nghỉ Dưỡng"
 * 4. Tìm kiếm trên 8 trường dữ liệu:
 *    amenity_name, amenity_code, location_detail, description, rules_and_regulations,
 *    category_name, category_code, block_name.
 * 5. Xếp hạng độ phù hợp (Relevance Scoring từ 0.00 đến 1.00):
 *    - Exact match (1.00)
 *    - Không dấu exact match (0.96)
 *    - Prefix match (0.90)
 *    - Cụm từ khớp trong tên (0.88)
 *    - Token match & Fuzzy match (0.70 - 0.85)
 *    - Secondary fields match (0.45 - 0.65)
 * 6. Tốc độ sub-millisecond với Single-Pass in-memory ranking.
 */
class SmartSearchDriver implements SearchDriverInterface
{
    /**
     * Bảng từ đồng âm / sai chính tả / viết tắt phổ biến
     *
     * @var array<string, array<int, string>>
     */
    protected static array $synonyms = [
        'ku' => ['khu'],
        'duon' => ['duong'],
        'gim' => ['gym'],
        'tenit' => ['tennis'],
        'tenis' => ['tennis'],
        'tennid' => ['tennis'],
        'bbq' => ['bbq', 'nuong', 'barbecue'],
        'toa' => ['toa', 'thap', 'block'],
        'thap' => ['thap', 'toa', 'block'],
        'boi' => ['boi', 'ho boi', 'be boi'],
        'gym' => ['gym', 'the hinh'],
        'knd' => ['khu nghi duong'],
    ];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected array $config = []) {}

    /**
     * Chuyển đổi chuỗi tiếng Việt có dấu thành không dấu (Tương thích ngược)
     */
    public static function stripVietnameseAccents(string $str): string
    {
        return VietnameseNormalizer::stripVietnameseAccents($str);
    }

    /**
     * Chuẩn hóa truy vấn tìm kiếm theo Section 3 (Tương thích ngược)
     *
     * @return array{
     *     raw: string,
     *     normalized: string,
     *     unaccented: string,
     *     tokens: array<int, string>,
     *     unaccented_tokens: array<int, string>
     * }
     */
    public static function normalizeQuery(string $query): array
    {
        return VietnameseNormalizer::normalize($query);
    }

    /**
     * So khớp Fuzzy giữa 1 token tìm kiếm và 1 từ trong văn bản
     */
    protected function matchTokenFuzzy(string $queryToken, string $targetWord): float
    {
        if ($queryToken === '' || $targetWord === '') {
            return 0.0;
        }

        if ($queryToken === $targetWord) {
            return 1.0;
        }

        // Kiểm tra từ đồng âm / gõ tắt trong từ điển
        if (isset(self::$synonyms[$queryToken]) && in_array($targetWord, self::$synonyms[$queryToken], true)) {
            return 0.95;
        }

        // Tiền tố (Prefix)
        if (str_starts_with($targetWord, $queryToken)) {
            return 0.90;
        }

        // Chứa từ (Contains)
        if (str_contains($targetWord, $queryToken)) {
            return 0.85;
        }

        // Levenshtein cho từ ngắn (<= 4 ký tự: tối đa 1 ký tự sai)
        $lenQ = mb_strlen($queryToken);
        $lenT = mb_strlen($targetWord);
        if ($lenQ <= 4 && $lenT <= 5) {
            $lev = levenshtein($queryToken, $targetWord);
            if ($lev <= 1) {
                return 0.80;
            }
        }

        // Levenshtein cho từ dài (>= 4 ký tự: tối đa 2 ký tự sai)
        if ($lenQ >= 4 && $lenT >= 4) {
            $lev = levenshtein($queryToken, $targetWord);
            if ($lev <= 2) {
                return 0.75;
            }
        }

        // Độ tương đồng similar_text
        similar_text($queryToken, $targetWord, $percent);
        if ($percent >= 75) {
            return round($percent / 100 * 0.80, 2);
        }

        return 0.0;
    }

    /**
     * Tính điểm tương quan (Relevance Score) của bản ghi tiện ích đối với từ khóa
     * Tìm kiếm trên 8 trường:
     * 1. amenity_name
     * 2. amenity_code
     * 3. location_detail
     * 4. description
     * 5. rules_and_regulations
     * 6. category_name
     * 7. category_code
     * 8. block_name
     *
     * @param  object  $item  Bản ghi tiện ích
     * @param  array{
     *     raw: string,
     *     normalized: string,
     *     unaccented: string,
     *     tokens: array<int, string>,
     *     unaccented_tokens: array<int, string>,
     *     corrected?: string,
     *     corrected_tokens?: array<int, string>,
     *     candidate_queries?: array<int, string>
     * }  $normQuery  Truy vấn đã chuẩn hóa
     */
    public function computeRelevanceScore(object $item, array $normQuery): float
    {
        $normalized = $normQuery['normalized'];
        $unaccented = $normQuery['unaccented'];
        $tokens = $normQuery['unaccented_tokens'];
        $corrected = $normQuery['corrected'] ?? $unaccented;
        $correctedTokens = $normQuery['corrected_tokens'] ?? $tokens;

        if (empty($tokens) && empty($correctedTokens)) {
            return 0.0;
        }

        $code = mb_strtolower((string) ($item->amenity_code ?? ''));
        $name = mb_strtolower((string) ($item->amenity_name ?? ''));
        $unaccentedName = VietnameseNormalizer::stripVietnameseAccents($name);
        $nameWords = array_values(array_filter(explode(' ', $unaccentedName), fn ($w) => $w !== ''));
        $tokensToEvaluate = ! empty($correctedTokens) ? $correctedTokens : $tokens;

        // 1. Khớp chính xác 100% Mã hoặc Tên tiện ích (Raw hoặc Corrected)
        if ($code === $normalized || $code === $unaccented || $code === $corrected) {
            return 1.00;
        }
        if ($name === $normalized) {
            return 1.00;
        }
        if ($unaccentedName === $unaccented || $unaccentedName === $corrected) {
            return 0.96;
        }

        // 2. Khớp tiền tố (Prefix) trên Mã hoặc Tên tiện ích
        if (str_starts_with($code, $unaccented) || str_starts_with($code, $corrected)) {
            return 0.94;
        }
        if (
            str_starts_with($name, $normalized) ||
            str_starts_with($unaccentedName, $unaccented) ||
            str_starts_with($unaccentedName, $corrected)
        ) {
            $coverageRatio = count($tokensToEvaluate) / max(count($nameWords), 1);

            return round(0.91 + min($coverageRatio * 0.03, 0.03), 2);
        }

        // 3. Khớp cụm từ trong tên tiện ích (Contains full phrase)
        if (str_contains($unaccentedName, $unaccented) || str_contains($unaccentedName, $corrected)) {
            $coverageRatio = count($tokensToEvaluate) / max(count($nameWords), 1);

            return round(0.87 + min($coverageRatio * 0.03, 0.03), 2);
        }

        // Chuẩn bị các trường văn bản mở rộng (8 trường)
        $location = VietnameseNormalizer::stripVietnameseAccents(mb_strtolower((string) ($item->location_detail ?? '')));
        $description = VietnameseNormalizer::stripVietnameseAccents(mb_strtolower((string) ($item->description ?? '')));
        $rules = VietnameseNormalizer::stripVietnameseAccents(mb_strtolower((string) ($item->rules_and_regulations ?? '')));
        $catName = VietnameseNormalizer::stripVietnameseAccents(mb_strtolower((string) ($item->category_name ?? '')));
        $catCode = mb_strtolower((string) ($item->category_code ?? ''));
        $blockName = VietnameseNormalizer::stripVietnameseAccents(mb_strtolower((string) ($item->block_name ?? '')));

        $combinedText = "{$unaccentedName} {$code} {$blockName} {$location} {$catName} {$catCode} {$description} {$rules}";
        $combinedWords = array_values(array_filter(explode(' ', $combinedText), fn ($w) => $w !== ''));

        $tokenScoresOnName = [];
        $tokenScoresGlobal = [];

        foreach ($tokensToEvaluate as $idx => $token) {
            $origToken = $tokens[$idx] ?? $token;

            // So khớp trên Tên tiện ích
            $bestNameScore = 0.0;
            foreach ($nameWords as $w) {
                $score1 = $this->matchTokenFuzzy($token, $w);
                $score2 = ($origToken !== $token) ? $this->matchTokenFuzzy($origToken, $w) : 0.0;
                $maxTokScore = max($score1, $score2);

                if ($maxTokScore > $bestNameScore) {
                    $bestNameScore = $maxTokScore;
                }
            }
            $tokenScoresOnName[] = $bestNameScore;

            // So khớp trên toàn bộ 8 trường
            $bestGlobalScore = $bestNameScore;
            if ($bestGlobalScore < 0.85) {
                foreach ($combinedWords as $w) {
                    $score1 = $this->matchTokenFuzzy($token, $w);
                    $score2 = ($origToken !== $token) ? $this->matchTokenFuzzy($origToken, $w) : 0.0;
                    $maxScore = max($score1, $score2);

                    if ($maxScore > $bestGlobalScore) {
                        $bestGlobalScore = $maxScore;
                    }
                }
            }
            $tokenScoresGlobal[] = $bestGlobalScore;
        }

        if (empty($tokenScoresOnName) || empty($tokenScoresGlobal)) {
            return 0.0;
        }

        $nameMinScore = min($tokenScoresOnName);
        $nameAvgScore = array_sum($tokenScoresOnName) / count($tokenScoresOnName);
        $coverageRatio = count($tokensToEvaluate) / max(count($nameWords), 1);
        $brevityBonus = round(min($coverageRatio * 0.03, 0.03), 3);

        // Trường hợp tất cả token đều khớp trên Tên tiện ích (kể cả mờ/fuzzy như "ku nghi duon" -> "khu nghi duong")
        if ($nameMinScore >= 0.70) {
            return round(0.72 + ($nameAvgScore * 0.10) + $brevityBonus, 2);
        }

        // Trường hợp một số từ khớp trên Tên và từ khác khớp trên Block / Location (ví dụ "gym toa a")
        $globalMinScore = min($tokenScoresGlobal);
        $globalAvgScore = array_sum($tokenScoresGlobal) / count($tokenScoresGlobal);

        if ($globalMinScore >= 0.70) {
            return round(0.70 + ($globalAvgScore * 0.12) + $brevityBonus, 2);
        }

        // Trường hợp khớp 1 phần (chỉ một số từ khớp, ví dụ "khu nghi" trong "Khu Nghỉ Dưỡng")
        $matchedCount = count(array_filter($tokenScoresGlobal, fn ($s) => $s >= 0.70));
        if ($matchedCount > 0) {
            $ratio = $matchedCount / count($tokensToEvaluate);
            if ($ratio >= 0.5) {
                return round(0.50 + ($ratio * 0.20), 2);
            }
        }

        return 0.0;
    }

    /**
     * Thực hiện tìm kiếm thông minh (Smart Search Engine)
     */
    public function search(string $index, string $query, array $filters = [], array $options = []): SearchResult
    {
        $startTime = microtime(true);

        // 1. Normalize Query
        $normQuery = VietnameseNormalizer::normalize($query);
        $trimmed = $normQuery['raw'];

        // 2. Spell Correction & Candidate Generation
        $correction = VietnameseSpellCorrector::correctQuery($query);
        $normQuery['corrected'] = $correction['corrected'];
        $normQuery['corrected_tokens'] = $correction['corrected_tokens'];
        $normQuery['candidate_queries'] = $correction['candidate_queries'];

        $page = max((int) ($options['page'] ?? 1), 1);
        $perPage = max(min((int) ($options['per_page'] ?? 10), 100), 1);
        $sort = $options['sort'] ?? 'relevance';

        if ($index === 'amenities') {
            $baseQuery = DB::table('amenities')
                ->leftJoin('amenity_categories', 'amenities.category_id', '=', 'amenity_categories.id')
                ->leftJoin('blocks', 'amenities.block_id', '=', 'blocks.id')
                ->whereNull('amenities.deleted_at')
                ->select(
                    'amenities.*',
                    'amenity_categories.category_name',
                    'amenity_categories.category_code',
                    'blocks.block_name',
                    'blocks.block_code'
                );

            // Áp dụng bộ lọc
            if (! empty($filters['category_id'])) {
                $baseQuery->where('amenities.category_id', $filters['category_id']);
            }
            if (! empty($filters['block_id'])) {
                $baseQuery->where('amenities.block_id', $filters['block_id']);
            }
            if (isset($filters['is_active']) && $filters['is_active'] !== 'all' && $filters['is_active'] !== null) {
                $baseQuery->where('amenities.is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
            }

            // Nếu không có từ khóa tìm kiếm -> Trả về danh sách thông thường theo phân trang
            if ($trimmed === '') {
                match ($sort) {
                    'name_asc' => $baseQuery->orderBy('amenities.amenity_name', 'asc'),
                    'name_desc' => $baseQuery->orderBy('amenities.amenity_name', 'desc'),
                    'price_asc' => $baseQuery->orderBy('amenities.hourly_rate', 'asc'),
                    'price_desc' => $baseQuery->orderBy('amenities.hourly_rate', 'desc'),
                    'created_at_asc' => $baseQuery->orderBy('amenities.created_at', 'asc'),
                    default => $baseQuery->orderBy('amenities.created_at', 'desc'),
                };

                $total = $baseQuery->count();
                $items = $baseQuery->skip(($page - 1) * $perPage)->take($perPage)->get()->toArray();
                $elapsedMs = (microtime(true) - $startTime) * 1000;

                return new SearchResult($items, $total, $page, $perPage, $elapsedMs, 'smart', false, [
                    'query' => $trimmed,
                    'normalized_query' => $normQuery['normalized'],
                    'corrected_query' => $correction['corrected'],
                ]);
            }

            // Có từ khóa tìm kiếm -> Lấy toàn bộ ứng viên đang hoạt động để chạy Smart Relevance Engine
            $candidates = $baseQuery->get();

            $scored = [];
            foreach ($candidates as $item) {
                $score = $this->computeRelevanceScore($item, $normQuery);
                if ($score >= 0.35) {
                    $itemArray = (array) $item;
                    $itemArray['relevance_score'] = $score;
                    $scored[] = $itemArray;
                }
            }

            // Sắp xếp
            if ($sort === 'relevance') {
                usort($scored, function ($a, $b) {
                    if ($b['relevance_score'] != $a['relevance_score']) {
                        return $b['relevance_score'] <=> $a['relevance_score'];
                    }

                    $lenA = mb_strlen($a['amenity_name'] ?? '');
                    $lenB = mb_strlen($b['amenity_name'] ?? '');
                    if ($lenA !== $lenB) {
                        return $lenA <=> $lenB; // Ưu tiên tên ngắn, cô đọng hơn
                    }

                    return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
                });
            } elseif ($sort === 'name_asc') {
                usort($scored, fn ($a, $b) => strcmp($a['amenity_name'] ?? '', $b['amenity_name'] ?? ''));
            } elseif ($sort === 'name_desc') {
                usort($scored, fn ($a, $b) => strcmp($b['amenity_name'] ?? '', $a['amenity_name'] ?? ''));
            } elseif ($sort === 'price_asc') {
                usort($scored, fn ($a, $b) => ($a['hourly_rate'] ?? 0) <=> ($b['hourly_rate'] ?? 0));
            } elseif ($sort === 'price_desc') {
                usort($scored, fn ($a, $b) => ($b['hourly_rate'] ?? 0) <=> ($a['hourly_rate'] ?? 0));
            } else {
                usort($scored, fn ($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
            }

            $total = count($scored);
            $sliced = array_slice($scored, ($page - 1) * $perPage, $perPage);
            $elapsedMs = (microtime(true) - $startTime) * 1000;

            return new SearchResult(
                $sliced,
                $total,
                $page,
                $perPage,
                $elapsedMs,
                'smart',
                $correction['is_corrected'] || true,
                [
                    'query' => $trimmed,
                    'normalized_query' => $normQuery['normalized'],
                    'corrected_query' => $correction['corrected'],
                ]
            );
        }

        $elapsedMs = (microtime(true) - $startTime) * 1000;

        return new SearchResult([], 0, $page, $perPage, $elapsedMs, 'smart', false, [
            'query' => $trimmed,
            'normalized_query' => $normQuery['normalized'],
            'corrected_query' => $correction['corrected'],
        ]);
    }

    /**
     * Gợi ý tìm kiếm nhanh (Autocomplete / Typeahead) bằng Fuzzy Engine
     */
    public function suggest(string $index, string $prefix, int $limit = 5): array
    {
        $norm = VietnameseNormalizer::normalize($prefix);
        if ($norm['raw'] === '' || mb_strlen($norm['raw']) < 2) {
            return [];
        }

        $correction = VietnameseSpellCorrector::correctQuery($prefix);
        $norm['corrected'] = $correction['corrected'];
        $norm['corrected_tokens'] = $correction['corrected_tokens'];

        $limit = max(min($limit, 20), 1);

        if ($index === 'amenities') {
            $candidates = DB::table('amenities')
                ->leftJoin('amenity_categories', 'amenities.category_id', '=', 'amenity_categories.id')
                ->leftJoin('blocks', 'amenities.block_id', '=', 'blocks.id')
                ->whereNull('amenities.deleted_at')
                ->select(
                    'amenities.id',
                    'amenities.amenity_name',
                    'amenities.amenity_code',
                    'amenities.location_detail',
                    'amenity_categories.category_name',
                    'blocks.block_name'
                )
                ->get();

            $scored = [];
            foreach ($candidates as $item) {
                $score = $this->computeRelevanceScore($item, $norm);
                if ($score >= 0.35) {
                    $scored[] = [
                        'id' => $item->id,
                        'label' => $item->amenity_name,
                        'code' => $item->amenity_code,
                        'category' => $item->category_name,
                        'block' => $item->block_name,
                        'type' => 'amenity',
                        'score' => $score,
                    ];
                }
            }

            usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

            return array_slice($scored, 0, $limit);
        }

        return [];
    }

    public function index(string $index, string $id, array $document): void {}

    public function delete(string $index, string $id): void {}

    public function flush(string $index): void {}
}
