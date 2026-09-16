<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Log;

/**
 * Vietnamese Dictionary Service (~74K words from Viet74K.txt)
 *
 * Nhiệm vụ:
 * 1. Đọc và tiền xử lý bộ từ điển duyet/vietnamese-wordlist (Viet74K.txt)
 * 2. Xây dựng chỉ mục tra cứu O(1) và chỉ mục phân vùng theo độ dài (Length-partitioned)
 * 3. Biên dịch thành mảng cache tối ưu với PHP OPcache để nạp trong < 2ms
 * 4. Cung cấp API tìm kiếm từ tương đồng chính xác dựa trên Levenshtein & Jaro-Winkler / similar_text
 */
class VietnameseDictionaryService
{
    /**
     * Tập từ vựng không dấu chuẩn -> Từ gốc có dấu
     *
     * @var array<string, string>
     */
    protected static array $unaccentedWordMap = [];

    /**
     * Tập từ vựng phân vùng theo độ dài ký tự
     *
     * @var array<int, array<int, string>>
     */
    protected static array $wordsByLength = [];

    /**
     * Trạng thái đã tải chỉ mục
     */
    protected static bool $isLoaded = false;

    /**
     * Đường dẫn file nguồn từ điển Viet74K.txt
     */
    public static function getSourcePath(): string
    {
        return storage_path('app/dictionary/Viet74K.txt');
    }

    /**
     * Đường dẫn file bộ nhớ đệm đã biên dịch
     */
    public static function getCompiledCachePath(): string
    {
        return storage_path('app/dictionary/viet74k_compiled.php');
    }

    /**
     * Tải và khởi tạo chỉ mục từ điển
     */
    public static function load(): void
    {
        if (self::$isLoaded) {
            return;
        }

        $compiledPath = self::getCompiledCachePath();

        if (file_exists($compiledPath)) {
            $data = require $compiledPath;
            if (is_array($data) && isset($data['map'], $data['by_length'])) {
                self::$unaccentedWordMap = $data['map'];
                self::$wordsByLength = $data['by_length'];
                self::$isLoaded = true;

                return;
            }
        }

        // Nếu chưa có file biên dịch, tiến hành phân tích từ Viet74K.txt
        self::buildIndex();
    }

    /**
     * Xây dựng chỉ mục từ điển từ Viet74K.txt và lưu cache
     */
    public static function buildIndex(): void
    {
        $sourcePath = self::getSourcePath();

        if (! file_exists($sourcePath)) {
            // Dự phòng từ vựng tiện ích cốt lõi nếu file từ điển chưa xuất hiện
            self::$unaccentedWordMap = [
                'khu' => 'khu', 'nghi' => 'nghỉ', 'duong' => 'dưỡng',
                'phong' => 'phòng', 'gym' => 'gym', 'ho' => 'hồ', 'boi' => 'bơi',
                'san' => 'sân', 'tennis' => 'tennis', 'bbq' => 'bbq',
                'toa' => 'tòa', 'nha' => 'nhà', 'can' => 'căn', 'ho' => 'hộ',
            ];
            self::$isLoaded = true;

            return;
        }

        $lines = file($sourcePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        $map = [];
        $byLength = [];

        // Bổ sung các từ mượn / từ vựng chung cư phổ biến không có trong từ điển cổ điển
        $domainWords = ['gym', 'tennis', 'bbq', 'barbecue', 'block', 'ruby', 'sapphire', 'diamond'];
        foreach ($domainWords as $dw) {
            $map[$dw] = $dw;
            $len = strlen($dw);
            $byLength[$len][] = $dw;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            // Chuẩn hóa dòng từ vựng
            $lowered = mb_strtolower($trimmed, 'UTF-8');
            // Tách các từ đơn lẻ nếu là cụm từ (ví dụ "khu nghỉ dưỡng" -> tách thành "khu", "nghỉ", "dưỡng")
            $wordsInLine = preg_split('/[\s\-_]+/', $lowered);
            if ($wordsInLine === false) {
                continue;
            }

            foreach ($wordsInLine as $w) {
                $w = trim($w);
                if ($w === '' || mb_strlen($w) < 2) {
                    continue;
                }

                $unaccented = VietnameseNormalizer::stripVietnameseAccents($w);
                if (! isset($map[$unaccented])) {
                    $map[$unaccented] = $w;
                    $len = strlen($unaccented);
                    $byLength[$len][] = $unaccented;
                }
            }
        }

        // Tối ưu hóa mảng byLength để loại bỏ trùng lặp
        foreach ($byLength as $len => $items) {
            $byLength[$len] = array_values(array_unique($items));
        }

        self::$unaccentedWordMap = $map;
        self::$wordsByLength = $byLength;
        self::$isLoaded = true;

        // Lưu cache biên dịch dạng PHP array
        try {
            $compiledPath = self::getCompiledCachePath();
            $export = "<?php\n\nreturn ".var_export([
                'map' => $map,
                'by_length' => $byLength,
            ], true).";\n";

            file_put_contents($compiledPath, $export, LOCK_EX);
        } catch (\Throwable $e) {
            Log::warning('Could not write compiled dictionary cache: '.$e->getMessage());
        }
    }

    /**
     * Kiểm tra một từ không dấu có tồn tại trong từ điển không
     */
    public static function hasWord(string $unaccentedWord): bool
    {
        self::load();

        return isset(self::$unaccentedWordMap[$unaccentedWord]);
    }

    /**
     * Lấy từ gốc tiếng Việt có dấu từ dạng không dấu
     */
    public static function getAccentedWord(string $unaccentedWord): ?string
    {
        self::load();

        return self::$unaccentedWordMap[$unaccentedWord] ?? null;
    }

    /**
     * Tìm từ gần nhất trong từ điển ~74K từ
     *
     * @param  string  $unaccentedToken  Từ cần sửa
     * @param  float  $minSimilarity  Độ tương đồng tối thiểu (0.00 - 1.00)
     * @param  int  $maxDistance  Khoảng cách Levenshtein tối đa
     * @return string|null Từ gợi ý chuẩn nhất hoặc null nếu không vượt qua ngưỡng
     */
    public static function findClosestWord(
        string $unaccentedToken,
        float $minSimilarity = 0.75,
        int $maxDistance = 2
    ): ?string {
        self::load();

        if (isset(self::$unaccentedWordMap[$unaccentedToken])) {
            return $unaccentedToken;
        }

        $len = strlen($unaccentedToken);
        if ($len < 2) {
            return null;
        }

        // Chỉ quét các từ có độ dài chênh lệch <= 1 ký tự
        $candidateLengths = [$len - 1, $len, $len + 1];
        $bestCandidate = null;
        $highestSimilarity = 0.0;
        $lowestLevenshtein = $maxDistance + 1;

        foreach ($candidateLengths as $targetLen) {
            if (! isset(self::$wordsByLength[$targetLen])) {
                continue;
            }

            foreach (self::$wordsByLength[$targetLen] as $dictWord) {
                // Kiểm tra ký tự đầu tiên nếu từ dài >= 4 để giảm không gian tìm kiếm
                if ($len >= 4 && $unaccentedToken[0] !== $dictWord[0]) {
                    continue;
                }

                $lev = levenshtein($unaccentedToken, $dictWord);
                if ($lev > $maxDistance) {
                    continue;
                }

                similar_text($unaccentedToken, $dictWord, $percent);
                $sim = $percent / 100.0;

                if ($sim >= $minSimilarity) {
                    // Ưu tiên khoảng cách Levenshtein thấp hơn, sau đó đến độ tương đồng
                    if ($lev < $lowestLevenshtein || ($lev === $lowestLevenshtein && $sim > $highestSimilarity)) {
                        $lowestLevenshtein = $lev;
                        $highestSimilarity = $sim;
                        $bestCandidate = $dictWord;
                    }
                }
            }
        }

        return $bestCandidate;
    }
}
