<?php

namespace App\Services\Search;

/**
 * Vietnamese Spell Corrector Service
 *
 * Thực hiện sửa lỗi gõ tiếng Việt thông minh theo Section 4 & 6:
 * 1. Phân tích ngữ âm và thói quen gõ tắt (ku -> khu, duon -> duong, gim -> gym, tenit -> tennis)
 * 2. So khớp từ điển ~74K từ với ngưỡng khoảng cách Levenshtein và độ tương đồng chặt chẽ
 * 3. Bảo vệ các từ không liên quan (ví dụ 'xyzabc') không bị sửa bừa bãi
 * 4. Tái cấu trúc cụm từ và sinh tập truy vấn ứng viên (Candidate Queries)
 */
class VietnameseSpellCorrector
{
    /**
     * Bảng quy tắc ngữ âm, viết tắt & thói quen gõ sai phổ biến trong tiện ích
     *
     * @var array<string, string>
     */
    protected static array $phoneticMap = [
        'ku' => 'khu',
        'duon' => 'duong',
        'gim' => 'gym',
        'thehinh' => 'the hinh',
        'tenit' => 'tennis',
        'tenis' => 'tennis',
        'tennid' => 'tennis',
        'tenys' => 'tennis',
        'bbq' => 'bbq',
        'nuon' => 'nuong',
        'nuog' => 'nuong',
        'boi' => 'boi',
        'be' => 'be',
        'ho' => 'ho',
        'knd' => 'khu nghi duong',
        'toa' => 'toa',
        'thap' => 'thap',
        'boxin' => 'boxing',
        'bok' => 'boxing',
        'kidzon' => 'kidzone',
        'kidzone' => 'kidzone',
        'playstation' => 'playstation',
        'vr' => 'vr',
        'game' => 'game',
        'skywalk' => 'skywalk',
        'spa' => 'spa',
        'yoga' => 'yoga',
        'golf' => 'golf',
    ];

    /**
     * Sửa lỗi chính tả một từ (token)
     */
    public static function correctToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }

        $unaccented = VietnameseNormalizer::stripVietnameseAccents(mb_strtolower($token, 'UTF-8'));

        // 1. Kiểm tra quy tắc ngữ âm / viết tắt đã định nghĩa O(1)
        if (isset(self::$phoneticMap[$unaccented])) {
            return self::$phoneticMap[$unaccented];
        }

        // 2. Nếu từ cực ngắn (<= 3 ký tự) hoặc là mã/số (vd: VR, BBQ, A1, B2) -> Giữ nguyên tuyệt đối
        if (mb_strlen($unaccented) <= 3 || preg_match('/[0-9\-_]/', $token)) {
            return $unaccented;
        }

        // 3. Nếu từ đã tồn tại chuẩn xác trong từ điển hoặc từ vựng tiện ích -> Giữ nguyên
        if (VietnameseDictionaryService::hasWord($unaccented)) {
            return $unaccented;
        }

        // 4. Tra cứu từ tương đồng CHỈ cho từ dài (>= 5 ký tự) với độ tương đồng rất cao (>= 85%)
        // Tránh triệt để việc sửa bừa các từ ngắn gây sai lệch kết quả tìm kiếm
        if (mb_strlen($unaccented) >= 5) {
            $closest = VietnameseDictionaryService::findClosestWord($unaccented, 0.85, 1);
            if ($closest) {
                return $closest;
            }
        }

        return $unaccented;
    }

    /**
     * Sửa lỗi chính tả toàn bộ câu truy vấn và trả về kết quả kèm danh sách ứng viên
     *
     * @return array{
     *     original: string,
     *     normalized: string,
     *     corrected: string,
     *     tokens: array<int, string>,
     *     corrected_tokens: array<int, string>,
     *     candidate_queries: array<int, string>,
     *     is_corrected: bool
     * }
     */
    public static function correctQuery(string $query): array
    {
        $norm = VietnameseNormalizer::normalize($query);
        $unaccentedTokens = $norm['unaccented_tokens'];

        if (empty($unaccentedTokens)) {
            return [
                'original' => $query,
                'normalized' => '',
                'corrected' => '',
                'tokens' => [],
                'corrected_tokens' => [],
                'candidate_queries' => [],
                'is_corrected' => false,
            ];
        }

        // Xử lý trường hợp viết tắt nguyên cụm (ví dụ 'knd' -> 'khu nghi duong')
        if (count($unaccentedTokens) === 1 && isset(self::$phoneticMap[$unaccentedTokens[0]])) {
            $mapped = self::$phoneticMap[$unaccentedTokens[0]];

            return [
                'original' => $query,
                'normalized' => $norm['unaccented'],
                'corrected' => $mapped,
                'tokens' => $unaccentedTokens,
                'corrected_tokens' => explode(' ', $mapped),
                'candidate_queries' => array_values(array_unique([$mapped, $norm['unaccented'], $norm['normalized']])),
                'is_corrected' => true,
            ];
        }

        $correctedTokens = [];
        $hasChange = false;

        foreach ($unaccentedTokens as $tok) {
            $corrected = self::correctToken($tok);
            $correctedTokens[] = $corrected;
            if ($corrected !== $tok) {
                $hasChange = true;
            }
        }

        $correctedQuery = implode(' ', $correctedTokens);

        // Sinh danh sách candidate queries theo thứ tự ưu tiên
        $candidates = array_values(array_filter(array_unique([
            $correctedQuery,
            $norm['unaccented'],
            $norm['normalized'],
            $norm['raw'],
        ]), fn ($c) => trim($c) !== ''));

        return [
            'original' => $query,
            'normalized' => $norm['unaccented'],
            'corrected' => $correctedQuery,
            'tokens' => $unaccentedTokens,
            'corrected_tokens' => $correctedTokens,
            'candidate_queries' => $candidates,
            'is_corrected' => $hasChange,
        ];
    }
}
