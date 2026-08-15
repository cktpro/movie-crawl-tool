<?php

namespace Movie\Crawler\MovieCrawler;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * Chọn nơi lưu ảnh crawl về: local (storage/app/public) hoặc Cloudflare R2.
 *
 * Cấu hình R2 lấy từ config('filesystems.disks.r2') (tức là các biến R2_* trong .env),
 * và bị ghi đè bởi các option tương ứng khai báo trong trang Crawler > Options nếu được điền.
 */
class ImageStorage
{
    const LOCAL_DISK = 'public';
    const R2_DISK = 'r2';

    /** Đã nạp config R2 vào runtime hay chưa (mỗi request chỉ cần 1 lần) */
    protected static $r2Configured = false;

    /**
     * 'local' | 'r2'
     */
    public static function driver(): string
    {
        return Option::get('image_storage_disk', 'local') === 'r2' ? 'r2' : 'local';
    }

    public static function disk(): FilesystemAdapter
    {
        if (static::driver() === 'r2') {
            static::configureR2();

            return Storage::disk(static::R2_DISK);
        }

        return Storage::disk(static::LOCAL_DISK);
    }

    /**
     * Ghi file, kèm ContentType/CacheControl khi đẩy lên R2.
     */
    public static function put(string $path, string $contents, string $mime = null): bool
    {
        if (static::driver() !== 'r2') {
            return (bool) static::disk()->put($path, $contents);
        }

        $options = ['CacheControl' => 'public, max-age=31536000'];
        if ($mime) {
            $options['ContentType'] = $mime;
        }

        return (bool) static::disk()->put($path, $contents, $options);
    }

    /**
     * URL công khai của ảnh đã lưu.
     */
    public static function url(string $path): string
    {
        if (static::driver() === 'r2') {
            $base = rtrim((string) (static::r2Config()['url'] ?? ''), '/');

            return $base ? $base . '/' . ltrim($path, '/') : static::disk()->url($path);
        }

        return Storage::disk(static::LOCAL_DISK)->url($path);
    }

    /**
     * Disk R2, bất kể option "Nơi lưu ảnh" đang chọn gì.
     *
     * disk() ở trên trả về nơi lưu ảnh ĐANG dùng để crawl. Công cụ chuyển ảnh lên R2
     * thì luôn cần chính R2 làm đích, kể cả khi site vẫn đang lưu ảnh ở local.
     */
    public static function r2Disk(): FilesystemAdapter
    {
        static::configureR2();

        return Storage::disk(static::R2_DISK);
    }

    /**
     * R2 đã có đủ thông tin để kết nối chưa.
     */
    public static function r2Ready(): bool
    {
        $c = static::r2Config();

        foreach (['bucket', 'key', 'secret', 'endpoint'] as $k) {
            if (empty($c[$k])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Domain công khai của bucket R2 (option r2_url), không có dấu / ở cuối.
     */
    public static function r2BaseUrl(): string
    {
        return rtrim((string) (static::r2Config()['url'] ?? ''), '/');
    }

    /**
     * URL công khai của một path trên R2.
     */
    public static function r2Url(string $path): string
    {
        $base = static::r2BaseUrl();

        return $base
            ? $base . '/' . ltrim($path, '/')
            : static::r2Disk()->url($path);
    }

    /**
     * Ghi file thẳng lên R2 kèm ContentType/CacheControl.
     */
    public static function putToR2(string $path, string $contents, ?string $mime = null): bool
    {
        $options = ['CacheControl' => 'public, max-age=31536000'];
        if ($mime) {
            $options['ContentType'] = $mime;
        }

        return (bool) static::r2Disk()->put($path, $contents, $options);
    }

    /**
     * PHP hiện tại có encode được WebP không (GD hoặc Imagick).
     */
    public static function supportsWebp(): bool
    {
        if (config('image.driver', 'gd') === 'imagick' && extension_loaded('imagick')) {
            return in_array('WEBP', \Imagick::queryFormats());
        }

        return function_exists('imagewebp');
    }

    /**
     * Config R2: .env trước, option trong admin ghi đè.
     */
    public static function r2Config(): array
    {
        $fromOptions = array_filter([
            'endpoint' => Option::get('r2_endpoint'),
            'bucket' => Option::get('r2_bucket'),
            'key' => Option::get('r2_access_key_id'),
            'secret' => Option::get('r2_secret_access_key'),
            'url' => Option::get('r2_url'),
        ], function ($value) {
            return !is_null($value) && trim((string) $value) !== '';
        });

        return array_merge([
            'driver' => 's3',
            'region' => 'auto',
            'use_path_style_endpoint' => true,
        ], (array) config('filesystems.disks.r2', []), $fromOptions);
    }

    /**
     * Nạp config R2 vào runtime rồi bỏ instance disk cũ để nó được dựng lại.
     */
    protected static function configureR2(): void
    {
        if (static::$r2Configured) {
            return;
        }

        config(['filesystems.disks.' . static::R2_DISK => static::r2Config()]);
        Storage::forgetDisk(static::R2_DISK);

        static::$r2Configured = true;
    }
}
