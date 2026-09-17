<?php

namespace App\Services\Search;

/**
 * Vietnamese Unicode & Diacritics Normalizer Service
 *
 * Thực hiện chuẩn hóa theo Section 3:
 * 1. Trim & Lowercase UTF-8
 * 2. Unicode Normalization (NFC / Normalizer::FORM_C)
 * 3. Chuẩn hóa khoảng trắng & loại bỏ ký tự dư thừa
 * 4. Chuyển đổi không dấu (Unaccented) bảo toàn cấu trúc âm tiết
 */
class VietnameseNormalizer
{
    /**
     * Bản đồ ánh xạ dấu tiếng Việt sang ký tự không dấu chuẩn
     *
     * @var array<string, array<int, string>>
     */
    protected static array $accentMap = [
        'a' => ['à', 'á', 'ạ', 'ả', 'ã', 'â', 'ầ', 'ấ', 'ậ', 'ẩ', 'ẫ', 'ă', 'ằ', 'ắ', 'ặ', 'ẳ', 'ẵ'],
        'e' => ['è', 'é', 'ẹ', 'ẻ', 'ẽ', 'ê', 'ề', 'ế', 'ệ', 'ể', 'ễ'],
        'i' => ['ì', 'í', 'ị', 'ỉ', 'ĩ'],
        'o' => ['ò', 'ó', 'ọ', 'ỏ', 'õ', 'ô', 'ồ', 'ố', 'ộ', 'ổ', 'ỗ', 'ơ', 'ờ', 'ớ', 'ợ', 'ở', 'ỡ'],
        'u' => ['ù', 'ú', 'ụ', 'ủ', 'ũ', 'ư', 'ừ', 'ứ', 'ự', 'ử', 'ữ'],
        'y' => ['ỳ', 'ý', 'ỵ', 'ỷ', 'ỹ'],
        'd' => ['đ', 'ð'],
        'A' => ['À', 'Á', 'Ạ', 'Ả', 'Ã', 'Â', 'Ầ', 'Ấ', 'Ậ', 'Ẩ', 'Ẫ', 'Ă', 'Ằ', 'Ắ', 'Ặ', 'Ẳ', 'Ẵ'],
        'E' => ['È', 'É', 'Ẹ', 'Ẻ', 'Ẽ', 'Ê', 'Ề', 'Ế', 'Ệ', 'Ể', 'Ễ'],
        'I' => ['Ì', 'Í', 'Ị', 'Ỉ', 'Ĩ'],
        'O' => ['Ò', 'Ó', 'Ọ', 'Ỏ', 'Õ', 'Ô', 'Ồ', 'Ố', 'Ộ', 'Ổ', 'Ỗ', 'Ơ', 'Ờ', 'Ớ', 'Ợ', 'Ở', 'Ỡ'],
        'U' => ['Ù', 'Ú', 'Ụ', 'Ủ', 'Ũ', 'Ư', 'Ừ', 'Ứ', 'Ự', 'Ử', 'Ữ'],
        'Y' => ['Ỳ', 'Ý', 'Ỵ', 'Ỷ', 'Ỹ'],
        'D' => ['Đ'],
    ];

    /**
     * Chuyển đổi chuỗi tiếng Việt có dấu thành không dấu
     */
    public static function stripVietnameseAccents(string $str): string
    {
        foreach (self::$accentMap as $nonAccent => $accentChars) {
            $str = str_replace($accentChars, $nonAccent, $str);
        }

        return $str;
    }

    /**
     * Chuẩn hóa toàn diện truy vấn tìm kiếm tiếng Việt
     *
     * @return array{
     *     raw: string,
     *     normalized: string,
     *     unaccented: string,
     *     tokens: array<int, string>,
     *     unaccented_tokens: array<int, string>
     * }
     */
    public static function normalize(string $query): array
    {
        $trimmed = trim($query);

        if ($trimmed === '') {
            return [
                'raw' => '',
                'normalized' => '',
                'unaccented' => '',
                'tokens' => [],
                'unaccented_tokens' => [],
            ];
        }

        // 1. Lowercase UTF-8
        $lowered = mb_strtolower($trimmed, 'UTF-8');

        // 2. Unicode Canonical Composition (NFC)
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::isNormalized($lowered, \Normalizer::FORM_C)
                ? $lowered
                : (\Normalizer::normalize($lowered, \Normalizer::FORM_C) ?: $lowered);
        } else {
            $normalized = $lowered;
        }

        // 3. Loại bỏ ký tự đặc biệt dư thừa (giữ chữ cái Unicode, chữ số và khoảng trắng)
        $cleanPunctuation = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized);
        $cleanPunctuation = is_string($cleanPunctuation) ? $cleanPunctuation : $normalized;

        // 4. Chuẩn hóa khoảng trắng (nhiều khoảng trắng liên tiếp -> 1 khoảng trắng)
        $cleanSpaces = preg_replace('/\s+/', ' ', $cleanPunctuation);
        $cleanSpaces = is_string($cleanSpaces) ? trim($cleanSpaces) : trim($cleanPunctuation);

        // 5. Phiên bản không dấu
        $unaccented = self::stripVietnameseAccents($cleanSpaces);

        // 6. Tách tokens (mảng các từ)
        $tokens = array_values(array_filter(explode(' ', $cleanSpaces), fn ($t) => $t !== ''));
        $unaccentedTokens = array_values(array_filter(explode(' ', $unaccented), fn ($t) => $t !== ''));

        return [
            'raw' => $trimmed,
            'normalized' => $cleanSpaces,
            'unaccented' => $unaccented,
            'tokens' => $tokens,
            'unaccented_tokens' => $unaccentedTokens,
        ];
    }
}
