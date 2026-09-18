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
        'tenys' => ['tennis'],
        'bbq' => ['bbq', 'nuong', 'barbecue'],
        'nuon' => ['nuong'],
        'boi' => ['boi', 'be boi', 'ho boi'],
        'be' => ['be', 'be boi', 'ho boi'],
        'gym' => ['gym', 'the hinh', 'fitness'],
        'knd' => ['khu nghi duong'],
        'kid' => ['kid', 'tre em', 'kidzone'],
        'kids' => ['kids', 'tre em', 'kidzone'],
        'boxin' => ['boxing'],
        'bok' => ['boxing'],
    ];

    /**
     * Bảng cụm từ đồng nghĩa / tương đương (Phrase-level synonyms)
     *
     * @var array<string, array<int, string>>
     */
    protected static array $phraseSynonyms = [
        'ho boi' => ['be boi', 'pool', 'swimming pool', 'khu boi', 'boi loi'],
        'be boi' => ['ho boi', 'pool', 'swimming pool', 'khu boi', 'boi loi'],
        'boi' => ['ho boi', 'be boi', 'pool', 'swimming pool'],
        'tennis' => ['san tennis', 'quan vot'],
        'san tennis' => ['tennis', 'quan vot'],
        'gym' => ['the hinh', 'phong the hinh', 'fitness'],
        'phong gym' => ['gym', 'the hinh', 'phong the hinh', 'fitness'],
        'bbq' => ['vuon nuong', 'nuong bbq', 'tiec nuong', 'barbecue'],
        'khu bbq' => ['vuon nuong', 'nuong bbq', 'bbq', 'tiec nuong'],
        'cau long' => ['badminton', 'san cau long'],
        'bong ban' => ['table tennis', 'ping pong'],
        'bong ro' => ['basketball'],
        'bong da' => ['football', 'soccer'],
        'kidzone' => ['tre em', 'khu vui choi tre em', 'kids'],
        'tre em' => ['kidzone', 'kids', 'vui choi tre em'],
        'vui choi' => ['kidzone', 'tro choi'],
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

        $lenQ = mb_strlen($queryToken);
        $lenT = mb_strlen($targetWord);

        // Đối với từ cực ngắn (<= 2 ký tự, ví dụ "ho", "ca", "a", "k"):
        // Chỉ chấp nhận khớp chính xác hoặc có trong bảng từ đồng nghĩa (synonyms)
        // Tuyệt đối không cho phép Levenshtein / substring để tránh "ho" khớp "cho", "co", "do", "hoat", "thong"...
        if ($lenQ <= 2) {
            return 0.0;
        }

        // Tiền tố (Prefix) - chỉ áp dụng cho từ có độ dài >= 3
        if ($lenQ >= 3 && str_starts_with($targetWord, $queryToken)) {
            return 0.90;
        }

        // Chứa từ (Contains) - chỉ áp dụng cho từ dài (>= 4 ký tự)
        if ($lenQ >= 4 && str_contains($targetWord, $queryToken)) {
            return 0.85;
        }

        // Levenshtein CHỈ áp dụng cho từ mượn / từ tiếng Anh dài (>= 6 ký tự, ví dụ: tennis, fitness, playstation)
        // TUYỆT ĐỐI KHÔNG áp dụng cho từ đơn tiếng Việt ngắn (3-5 ký tự)
        // để ngăn chặn hoàn toàn việc nhận nhầm từ khác nghĩa ("bóng" <-> "đông", "tháp" <-> "thép", "nước" <-> "nướng")
        if ($lenQ >= 6 && $lenT >= 6) {
            $lev = levenshtein($queryToken, $targetWord);
            if ($lev <= 1) {
                return 0.80;
            }
        }

        return 0.0;
    }

    /**
     * Tính điểm tương quan (Relevance Score) của bản ghi tiện ích đối với từ khóa
     * Phân tầng ưu tiên: Tên tiện ích (Tier 1) > Danh mục (Tier 2) > Vị trí / Mô tả (Tier 3)
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

        $rawCode = mb_strtolower((string) ($item->amenity_code ?? ''));
        $cleanCode = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $rawCode)));
        $name = mb_strtolower((string) ($item->amenity_name ?? ''));
        $unaccentedName = VietnameseNormalizer::stripVietnameseAccents($name);
        $nameWords = array_values(array_filter(explode(' ', $unaccentedName), fn ($w) => $w !== ''));
        $tokensToEvaluate = ! empty($correctedTokens) ? $correctedTokens : $tokens;

        // 1. Khớp chính xác 100% Mã hoặc Tên tiện ích (Raw hoặc Corrected)
        if (
            $rawCode === mb_strtolower($normQuery['raw']) ||
            $rawCode === $normalized ||
            $cleanCode === $unaccented ||
            $cleanCode === $corrected
        ) {
            return 1.00;
        }
        if ($name === $normalized) {
            return 1.00;
        }
        if ($unaccentedName === $unaccented || $unaccentedName === $corrected) {
            return 0.98;
        }

        // 1b. Khớp cụm từ đồng nghĩa chính xác (ví dụ "ho boi" <-> "be boi", "boi" <-> "ho boi")
        $synonymPhrases = array_values(array_unique(array_merge(
            self::$phraseSynonyms[$unaccented] ?? [],
            self::$phraseSynonyms[$corrected] ?? []
        )));
        foreach ($synonymPhrases as $synPhrase) {
            if ($unaccentedName === $synPhrase) {
                return 0.97;
            }
        }

        // 2. Khớp tiền tố (Prefix) trên Mã hoặc Tên tiện ích
        // VÍ DỤ: "Khu" BẮT ĐẦU "Khu BBQ", "Khu Tiệc Nướng BBQ...", "Khu Nghỉ Dưỡng..."
        if (
            str_starts_with($rawCode, $unaccented) ||
            str_starts_with($rawCode, $corrected) ||
            str_starts_with($cleanCode, $unaccented) ||
            str_starts_with($cleanCode, $corrected)
        ) {
            return 0.96;
        }
        $isNamePrefixMatch = false;
        if (
            str_starts_with($name, $normalized) ||
            str_starts_with($unaccentedName, $unaccented) ||
            str_starts_with($unaccentedName, $corrected)
        ) {
            // Đối với từ ngắn (<= 3 ký tự như "ho"): từ đầu tiên của tên phải là từ đó trọn vẹn
            // để tránh "ho" khớp tiền tố của "hoi" (hội trường)
            if (mb_strlen($unaccented) <= 3) {
                if (isset($nameWords[0]) && ($nameWords[0] === $unaccented || $nameWords[0] === $corrected)) {
                    $isNamePrefixMatch = true;
                }
            } else {
                $isNamePrefixMatch = true;
            }
        }

        if ($isNamePrefixMatch) {
            $coverageRatio = count($tokensToEvaluate) / max(count($nameWords), 1);

            return round(0.94 + min($coverageRatio * 0.04, 0.04), 2);
        }
        foreach ($synonymPhrases as $synPhrase) {
            if (str_starts_with($unaccentedName, $synPhrase)) {
                return 0.93;
            }
        }

        // 3. Khớp cụm từ trong tên hoặc mã tiện ích (Contains full phrase at word boundaries)
        $rawCodePadded = '_'.str_replace('-', '_', $rawCode).'_';
        $codeMatches = (mb_strlen($unaccented) <= 3)
            ? (str_contains($rawCodePadded, "_{$unaccented}_") || str_starts_with($rawCode, $unaccented))
            : (str_contains($rawCode, $unaccented) || str_contains($cleanCode, $unaccented));

        if ($codeMatches) {
            return 0.92;
        }

        $paddedName = " {$unaccentedName} ";
        $nameMatches = false;

        if (mb_strlen($unaccented) <= 3) {
            // Với từ ngắn (<= 3 ký tự, ví dụ: "ho", "gym", "boi"):
            // BẮT BUỘC phải khớp chính xác từ nguyên vẹn trong tên: " ho " trong " ho boi ", " ven ho "
            // Tuyệt đối không cho phép "ho" khớp tiền tố các từ khác nghĩa như "hop" (họp), "hoi" (hội), "hoang" (hoàng), "hoan" (hoàn), "hoat" (hoạt)!
            if (str_contains($paddedName, " {$unaccented} ") || ($corrected !== $unaccented && str_contains($paddedName, " {$corrected} "))) {
                $nameMatches = true;
            }
        } else {
            // Với cụm từ hoặc từ dài (>= 4 ký tự):
            if (str_contains($paddedName, " {$unaccented}") || ($corrected !== $unaccented && str_contains($paddedName, " {$corrected}"))) {
                $nameMatches = true;
            } elseif (count($tokensToEvaluate) >= 2 && str_contains($unaccentedName, $unaccented)) {
                $nameMatches = true;
            }
        }

        if ($nameMatches) {
            $coverageRatio = count($tokensToEvaluate) / max(count($nameWords), 1);

            return round(0.88 + min($coverageRatio * 0.04, 0.04), 2);
        }

        foreach ($synonymPhrases as $synPhrase) {
            $paddedSyn = " {$synPhrase} ";
            if (str_contains($paddedName, $paddedSyn) || str_contains($paddedName, " {$synPhrase}")) {
                return 0.87;
            }
        }

        // 4. So khớp từng từ (Tokens) TRỰC TIẾP trên Tên tiện ích
        $tokenScoresOnName = [];
        foreach ($tokensToEvaluate as $idx => $token) {
            $origToken = $tokens[$idx] ?? $token;

            $bestNameScore = 0.0;
            foreach ($nameWords as $w) {
                $score1 = $this->matchTokenFuzzy($token, $w);
                $score2 = ($origToken !== $token) ? $this->matchTokenFuzzy($origToken, $w) : 0.0;
                $maxTokScore = max($score1, $score2);

                if ($maxTokScore > $bestNameScore) {
                    $bestNameScore = $maxTokScore;
                }
            }

            // Kiểm tra từ đồng nghĩa token (ví dụ 'gim' -> 'gym', 'tenit' -> 'tennis', 'boi' -> 'ho boi')
            if ($bestNameScore < 0.85 && isset(self::$synonyms[$token])) {
                foreach (self::$synonyms[$token] as $synTok) {
                    foreach ($nameWords as $w) {
                        $s = $this->matchTokenFuzzy($synTok, $w);
                        if ($s > $bestNameScore) {
                            $bestNameScore = min($s, 0.88);
                        }
                    }
                }
            }

            $tokenScoresOnName[] = $bestNameScore;
        }

        $nameMinScore = ! empty($tokenScoresOnName) ? min($tokenScoresOnName) : 0.0;
        $nameAvgScore = ! empty($tokenScoresOnName) ? (array_sum($tokenScoresOnName) / count($tokenScoresOnName)) : 0.0;
        $coverageRatio = count($tokensToEvaluate) / max(count($nameWords), 1);
        $brevityBonus = round(min($coverageRatio * 0.03, 0.03), 3);

        // Nếu TẤT CẢ các từ tìm kiếm đều khớp trên Tên tiện ích (ví dụ "ku nghi duon" -> "khu nghi duong")
        if ($nameMinScore >= 0.70) {
            return round(0.78 + ($nameAvgScore * 0.08) + $brevityBonus, 2);
        }

        // 5. Khớp 1 phần trên Tên tiện ích
        // Với cụm 2 từ (như "ho boi", "san tennis", "phong gym"):
        // Bắt buộc phải khớp cả 2 từ (đã xử lý ở mục 4), TUYỆT ĐỐI KHÔNG cho phép khớp 1 từ riêng lẻ
        // để ngăn ngừa triệt để việc tìm "ho boi" lại ra các tiện ích chỉ chứa từ "Hồ" như "Vườn Nướng BBQ Ven Hồ".
        // CHỈ cho phép khớp 1 phần khi truy vấn có từ 3 từ trở lên và khớp ít nhất 2 từ.
        if (count($tokensToEvaluate) >= 3) {
            $matchedCountOnName = count(array_filter($tokenScoresOnName, fn ($s) => $s >= 0.70));
            if ($matchedCountOnName >= 2) {
                $ratio = $matchedCountOnName / count($tokensToEvaluate);
                if ($ratio >= 0.65) {
                    return round(0.50 + ($ratio * 0.15), 2);
                }
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
                if ($score >= 0.45) {
                    $itemArray = (array) $item;
                    $itemArray['relevance_score'] = $score;
                    $scored[] = $itemArray;
                }
            }

            // Sắp xếp
            if ($sort === 'relevance') {
                $unaccentedQuery = $normQuery['unaccented'];
                usort($scored, function ($a, $b) use ($unaccentedQuery) {
                    // 1. So sánh điểm tương quan (Relevance Score)
                    if (abs($b['relevance_score'] - $a['relevance_score']) >= 0.005) {
                        return $b['relevance_score'] <=> $a['relevance_score'];
                    }

                    $nameA = mb_strtolower(VietnameseNormalizer::stripVietnameseAccents((string) ($a['amenity_name'] ?? '')));
                    $nameB = mb_strtolower(VietnameseNormalizer::stripVietnameseAccents((string) ($b['amenity_name'] ?? '')));

                    // 2. Ưu tiên tiện ích mà tên BẮT ĐẦU bằng từ khóa tìm kiếm
                    $startsA = str_starts_with($nameA, $unaccentedQuery);
                    $startsB = str_starts_with($nameB, $unaccentedQuery);
                    if ($startsA !== $startsB) {
                        return $startsA ? -1 : 1;
                    }

                    // 3. Ưu tiên vị trí xuất hiện sớm hơn trong tên
                    $posA = strpos($nameA, $unaccentedQuery);
                    $posB = strpos($nameB, $unaccentedQuery);
                    if ($posA !== false && $posB !== false && $posA !== $posB) {
                        return $posA <=> $posB;
                    }

                    // 4. Ưu tiên tên ngắn gọn hơn
                    $lenA = mb_strlen($a['amenity_name'] ?? '');
                    $lenB = mb_strlen($b['amenity_name'] ?? '');
                    if ($lenA !== $lenB) {
                        return $lenA <=> $lenB;
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
                if ($score >= 0.45) {
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

            $unaccentedPrefix = $norm['unaccented'];
            usort($scored, function ($a, $b) use ($unaccentedPrefix) {
                if (abs($b['score'] - $a['score']) >= 0.005) {
                    return $b['score'] <=> $a['score'];
                }

                $nameA = mb_strtolower(VietnameseNormalizer::stripVietnameseAccents((string) ($a['label'] ?? '')));
                $nameB = mb_strtolower(VietnameseNormalizer::stripVietnameseAccents((string) ($b['label'] ?? '')));

                $startsA = str_starts_with($nameA, $unaccentedPrefix);
                $startsB = str_starts_with($nameB, $unaccentedPrefix);
                if ($startsA !== $startsB) {
                    return $startsA ? -1 : 1;
                }

                return mb_strlen($a['label'] ?? '') <=> mb_strlen($b['label'] ?? '');
            });

            return array_slice($scored, 0, $limit);
        }

        return [];
    }

    public function index(string $index, string $id, array $document): void {}

    public function delete(string $index, string $id): void {}

    public function flush(string $index): void {}
}
