<?php

namespace Ophim\Crawler\OphimCrawler;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Tìm các thư mục "images/{folder}" (trên disk local hoặc R2) không còn được
 * movie nào tham chiếu tới — tức ảnh crawl về nhưng phim đã bị xoá khỏi DB,
 * hoặc slug phim đã đổi khiến thư mục ảnh cũ bị bỏ rơi.
 *
 * Cách xác định "đang dùng": quét toàn bộ movies.thumb_url/poster_url, lấy tên
 * thư mục ngay sau "images/" trong URL — vì đó chính là đường dẫn ImageStorage
 * đã dùng để lưu ảnh (xem ImageStorage::url() và Collector::getImage()).
 * Không dựa vào movies.slug hiện tại, vì slug có thể đã đổi sau khi ảnh được lưu.
 */
class OrphanImageScanner
{
    /**
     * Tập hợp tên thư mục (dạng slug) đang được movies.thumb_url/poster_url tham chiếu.
     */
    public static function referencedFolders(): array
    {
        $folders = [];

        DB::table('movies')->select('thumb_url', 'poster_url')
            ->orderBy('id')
            ->chunk(1000, function ($rows) use (&$folders) {
                foreach ($rows as $row) {
                    foreach ([$row->thumb_url, $row->poster_url] as $url) {
                        if ($folder = static::extractFolder($url)) {
                            $folders[$folder] = true;
                        }
                    }
                }
            });

        return $folders;
    }

    public static function extractFolder(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        if (preg_match('~images/([^/?#]+)/~', $url, $m)) {
            return rawurldecode($m[1]);
        }

        return null;
    }

    /**
     * Quét một disk ('public' tức local, hoặc 'r2') và trả về danh sách thư mục mồ côi.
     *
     * @return array<int, array{folder:string,disk:string,path:string,files:int,size:int,last_modified:int,safe_to_delete:bool}>
     */
    public static function scan(string $disk, int $minAgeHours = 24): array
    {
        // Disk 'r2' cần được cấu hình qua ImageStorage trước (gộp .env + option admin)
        // trước khi Storage::disk('r2') dùng đúng credentials/endpoint.
        $storage = $disk === ImageStorage::R2_DISK
            ? ImageStorage::disk()
            : Storage::disk($disk);
        $referenced = static::referencedFolders();
        $cutoff = Carbon::now()->subHours($minAgeHours)->getTimestamp();

        $orphans = [];

        foreach ($storage->directories('images') as $dirPath) {
            $folder = Str::after($dirPath, 'images/');

            if ($folder === '' || isset($referenced[$folder])) {
                continue;
            }

            $files = $storage->allFiles($dirPath);
            $size = 0;
            $lastModified = 0;

            foreach ($files as $file) {
                $size += (int) $storage->size($file);
                $lastModified = max($lastModified, (int) $storage->lastModified($file));
            }

            $orphans[] = [
                'folder' => $folder,
                'disk' => $disk,
                'path' => $dirPath,
                'files' => count($files),
                'size' => $size,
                'last_modified' => $lastModified,
                // Thư mục rỗng hoặc đã "nguội" quá $minAgeHours giờ mới coi là an toàn để xoá,
                // để tránh đụng vào ảnh vừa crawl xong nhưng movie chưa kịp lưu vào DB
                // (Collector::getImage() tải & lưu ảnh TRƯỚC khi Movie::create()/update() chạy).
                'safe_to_delete' => $lastModified === 0 || $lastModified < $cutoff,
            ];
        }

        usort($orphans, fn ($a, $b) => $b['size'] <=> $a['size']);

        return $orphans;
    }

    /**
     * Xoá các thư mục đã chọn. $items là mảng ['disk' => 'path', ...] hoặc danh sách
     * kết quả từ scan() lọc sẵn — hàm sẽ nhóm theo disk trước khi xoá.
     *
     * @param array $orphans kết quả (hoặc tập con) trả về từ scan()
     * @return array{deleted:array,freed_bytes:int}
     */
    public static function delete(array $orphans): array
    {
        $deleted = [];
        $freedBytes = 0;

        foreach ($orphans as $item) {
            $storage = $item['disk'] === ImageStorage::R2_DISK
                ? ImageStorage::disk()
                : Storage::disk($item['disk']);
            $storage->deleteDirectory($item['path']);
            $deleted[] = $item['path'];
            $freedBytes += $item['size'];
        }

        return ['deleted' => $deleted, 'freed_bytes' => $freedBytes];
    }

    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = $bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, 2) . ' ' . $units[$i];
    }
}
