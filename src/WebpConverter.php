<?php

namespace Movie\Crawler\MovieCrawler;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManagerStatic as Image;
use Movie\Core\Models\Movie;

/**
 * Chuyển ảnh của các phim ĐANG CÓ sang định dạng WebP rồi đổi luôn link trong DB.
 *
 * Khác với option "Định dạng ảnh lưu" ở trang Options — chỗ đó chỉ áp dụng cho ảnh
 * tải về trong lúc crawl, ảnh cũ vẫn giữ nguyên jpg/png. Công cụ này lo phần ảnh cũ.
 *
 * Nguyên tắc (giống R2ImageMigrator để hai công cụ hành xử như nhau):
 * - Ảnh nằm ở đâu thì bản WebP ghi lại đúng chỗ đó: file local ghi vào disk public,
 *   ảnh trên R2 ghi ngược lên R2. Không tự ý dời ảnh sang nơi khác.
 * - Chạy lại được nhiều lần: file .webp đã tồn tại thì không encode lại, chỉ đổi link.
 * - Xoá file gốc là TUỲ CHỌN và mặc định tắt, vì không thể lùi lại được.
 * - Đổi link bằng query builder chứ không qua model: tránh chạm vào updated_at, nếu
 *   không toàn bộ phim sẽ trông như vừa cập nhật và làm hỏng thứ tự "phim mới".
 */
class WebpConverter
{
    /** Các cột ảnh trong bảng movies */
    const COT_ANH = ['thumb_url', 'poster_url'];

    const LOAI_RONG = 'rong';      // không có ảnh
    const LOAI_WEBP = 'webp';      // đã là webp
    const LOAI_LOCAL = 'local';    // file trên disk public của site
    const LOAI_R2 = 'r2';          // file trên bucket R2
    const LOAI_NGOAI = 'ngoai';    // URL của nguồn ngoài, phải tải về
    const LOAI_KHAC = 'khac';      // không nhận dạng được (vd tên file trần)

    /** Các loại chuyển được mà không cần đi ra mạng ngoài */
    const LOAI_CHUYEN_DUOC = [self::LOAI_LOCAL, self::LOAI_R2];

    /**
     * Phân loại một URL ảnh để biết phải làm gì với nó.
     */
    public static function phanLoai(?string $url): string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return static::LOAI_RONG;
        }

        if (static::laWebp($url)) {
            return static::LOAI_WEBP;
        }

        $r2Base = ImageStorage::r2BaseUrl();
        if ($r2Base !== '' && str_starts_with($url, $r2Base . '/')) {
            return static::LOAI_R2;
        }

        // Dùng chung cách nhận diện file local với R2ImageMigrator để hai trang
        // không bao giờ phân loại lệch nhau trên cùng một URL.
        if (R2ImageMigrator::duongDanLocal($url) !== null) {
            return static::LOAI_LOCAL;
        }

        if (preg_match('~^(https?:)?//~i', $url)) {
            return static::LOAI_NGOAI;
        }

        return static::LOAI_KHAC;
    }

    public static function laWebp(string $url): bool
    {
        return (bool) preg_match('/\.webp$/i', (string) strtok($url, '?'));
    }

    /**
     * Đường dẫn tương đối trong bucket R2, suy ra từ URL công khai.
     */
    public static function duongDanR2(string $url): ?string
    {
        $base = ImageStorage::r2BaseUrl();

        if ($base === '' || ! str_starts_with($url, $base . '/')) {
            return null;
        }

        return ltrim(substr((string) strtok($url, '?'), strlen($base)), '/');
    }

    /**
     * Đổi phần đuôi của một đường dẫn thành .webp.
     */
    public static function doiDuoiWebp(string $duongDan): string
    {
        return preg_replace('/\.[a-zA-Z0-9]+$/', '', $duongDan) . '.webp';
    }

    /**
     * Đếm số ảnh theo từng loại — dùng cho màn hình tổng quan, không đọc file nào.
     */
    public static function thongKe(): array
    {
        $dem = [];
        foreach (static::COT_ANH as $cot) {
            $dem[$cot] = [
                static::LOAI_RONG => 0,
                static::LOAI_WEBP => 0,
                static::LOAI_LOCAL => 0,
                static::LOAI_R2 => 0,
                static::LOAI_NGOAI => 0,
                static::LOAI_KHAC => 0,
            ];
        }

        Movie::select(array_merge(['id'], static::COT_ANH))->chunkById(500, function ($phims) use (&$dem) {
            foreach ($phims as $phim) {
                foreach (static::COT_ANH as $cot) {
                    $dem[$cot][static::phanLoai($phim->{$cot})]++;
                }
            }
        });

        return $dem;
    }

    /**
     * Danh sách phim còn ảnh chưa phải WebP.
     *
     * @param  int  $limit  0 = không giới hạn
     * @param  bool  $kemNguonNgoai  tính cả ảnh còn trỏ sang nguồn ngoài (phải tải về)
     */
    public static function quet(int $limit = 0, bool $kemNguonNgoai = false): array
    {
        $loaiNhan = static::LOAI_CHUYEN_DUOC;
        if ($kemNguonNgoai) {
            $loaiNhan[] = static::LOAI_NGOAI;
        }

        $ketQua = [];

        Movie::select(array_merge(['id', 'slug', 'name'], static::COT_ANH))
            ->orderBy('id')
            ->chunkById(500, function ($phims) use (&$ketQua, $limit, $loaiNhan) {
                foreach ($phims as $phim) {
                    $viec = [];
                    foreach (static::COT_ANH as $cot) {
                        $loai = static::phanLoai($phim->{$cot});
                        if (in_array($loai, $loaiNhan, true)) {
                            $viec[$cot] = ['loai' => $loai, 'url' => $phim->{$cot}];
                        }
                    }

                    if ($viec) {
                        $ketQua[] = [
                            'id' => $phim->id,
                            'slug' => $phim->slug,
                            'name' => $phim->name,
                            'viec' => $viec,
                        ];
                    }

                    if ($limit > 0 && count($ketQua) >= $limit) {
                        return false;
                    }
                }
            });

        return $limit > 0 ? array_slice($ketQua, 0, $limit) : $ketQua;
    }

    /**
     * Chuyển ảnh của một phim sang WebP.
     *
     * @param  bool  $thatSu  false = chỉ mô phỏng, không ghi file và không đổi DB
     * @param  bool  $xoaGoc  xoá file gốc sau khi ghi bản WebP thành công
     * @return array{doi: array, bo_qua: array, loi: array, bytes_truoc: int, bytes_sau: int, da_xoa: int}
     */
    public static function chuyenMotPhim(array $phim, bool $thatSu = false, bool $xoaGoc = false): array
    {
        $doi = [];
        $boQua = [];
        $loi = [];
        $bytesTruoc = 0;
        $bytesSau = 0;
        $daXoa = 0;
        $capNhat = [];

        foreach ($phim['viec'] as $cot => $tt) {
            try {
                $dich = static::dichCuaAnh($phim['slug'], $tt);
                if ($dich === null) {
                    $loi[$cot] = 'không xác định được nơi ghi ảnh mới';
                    continue;
                }

                [$disk, $duongDanMoi, $urlMoi] = [$dich['disk'], $dich['path'], $dich['url']];
                $laR2 = $dich['r2'];

                if (! $thatSu) {
                    $doi[$cot] = ['tu' => $tt['url'], 'den' => $urlMoi];
                    continue;
                }

                // Bản WebP đã có sẵn (lần chạy trước đứt giữa chừng) thì chỉ đổi link.
                if ($disk->exists($duongDanMoi)) {
                    $boQua[$cot] = 'đã có bản webp';
                } else {
                    $goc = static::layNoiDung($tt);
                    if ($goc === null || $goc === '') {
                        $loi[$cot] = 'không đọc được ảnh nguồn';
                        continue;
                    }

                    $webp = static::encodeWebp($goc);
                    if ($webp === null) {
                        $loi[$cot] = 'không encode được sang webp';
                        continue;
                    }

                    if (! static::ghi($disk, $laR2, $duongDanMoi, $webp)) {
                        $loi[$cot] = 'ghi file webp thất bại';
                        continue;
                    }

                    $bytesTruoc += strlen($goc);
                    $bytesSau += strlen($webp);
                }

                // Chỉ xoá file gốc khi bản WebP chắc chắn đã nằm trên disk, và tất nhiên
                // không xoá khi gốc với đích trùng tên (không xảy ra vì gốc không phải webp).
                if ($xoaGoc && ($duongDanGoc = static::duongDanGoc($tt)) && $duongDanGoc !== $duongDanMoi) {
                    if ($disk->exists($duongDanMoi) && $disk->exists($duongDanGoc)) {
                        $disk->delete($duongDanGoc);
                        $daXoa++;
                    }
                }

                $capNhat[$cot] = $urlMoi;
                $doi[$cot] = ['tu' => $tt['url'], 'den' => $urlMoi];
            } catch (\Throwable $e) {
                // \Throwable chứ không phải \Exception: Image::make() ném TypeError/Error
                // với ảnh hỏng, mà \Error không bị catch (\Exception) bắt — một ảnh lỗi
                // sẽ giết cả lô nếu bắt hụt.
                $loi[$cot] = $e->getMessage();
            }
        }

        // Ghi bằng query builder để không đụng updated_at và không kích hoạt event.
        if ($thatSu && $capNhat) {
            DB::table('movies')->where('id', $phim['id'])->update($capNhat);
        }

        return [
            'doi' => $doi,
            'bo_qua' => $boQua,
            'loi' => $loi,
            'bytes_truoc' => $bytesTruoc,
            'bytes_sau' => $bytesSau,
            'da_xoa' => $daXoa,
        ];
    }

    /**
     * Nơi sẽ ghi bản WebP: disk nào, đường dẫn nào, URL mới ra sao.
     *
     * Ảnh ở đâu ghi lại đúng đó. Riêng ảnh nguồn ngoài chưa nằm trong site thì đưa về
     * disk mà crawler đang dùng (option "Nơi lưu ảnh").
     *
     * @return array{disk: \Illuminate\Filesystem\FilesystemAdapter, r2: bool, path: string, url: string}|null
     */
    protected static function dichCuaAnh(string $slug, array $tt): ?array
    {
        if ($tt['loai'] === static::LOAI_LOCAL) {
            $path = R2ImageMigrator::duongDanLocal($tt['url']);
            if ($path === null) {
                return null;
            }

            // Giữ nguyên hình thức link cũ: tương đối vẫn tương đối, tuyệt đối vẫn tuyệt đối.
            return static::dichLocal(
                static::doiDuoiWebp($path),
                ! preg_match('~^(https?:)?//~i', trim((string) $tt['url']))
            );
        }

        if ($tt['loai'] === static::LOAI_R2) {
            $path = static::duongDanR2($tt['url']);
            if ($path === null) {
                return null;
            }

            return static::dichR2(static::doiDuoiWebp($path));
        }

        if ($tt['loai'] === static::LOAI_NGOAI) {
            // Giữ đúng quy ước images/{slug}/{tên file} như lúc crawl.
            $ten = basename((string) strtok($tt['url'], '?'));
            if ($ten === '' || $ten === '/' || ! str_contains($ten, '.')) {
                $ten = $slug . '.jpg';
            }

            $moi = static::doiDuoiWebp('images/' . $slug . '/' . $ten);

            return ImageStorage::driver() === 'r2' ? static::dichR2($moi) : static::dichLocal($moi);
        }

        return null;
    }

    /**
     * @param  bool  $tuongDoi  trả link dạng "/storage/..." thay vì kèm domain
     */
    protected static function dichLocal(string $duongDan, bool $tuongDoi = true): array
    {
        $disk = Storage::disk(ImageStorage::LOCAL_DISK);
        $url = $disk->url($duongDan);

        // Storage::url() luôn ghép APP_URL vào, trong khi DB đang lưu link tương đối
        // dạng "/storage/images/...". Không cắt lại thì mỗi lần chuyển đổi là một lần
        // gắn cứng domain vào DB — đổi domain sau này phải sửa hàng loạt, và trên máy
        // dev ảnh sẽ trỏ ngược về domain production (nơi chưa có file .webp vừa tạo).
        if ($tuongDoi) {
            $url = parse_url($url, PHP_URL_PATH) ?: $url;
        }

        return ['disk' => $disk, 'r2' => false, 'path' => $duongDan, 'url' => $url];
    }

    protected static function dichR2(string $duongDan): array
    {
        return [
            'disk' => ImageStorage::r2Disk(),
            'r2' => true,
            'path' => $duongDan,
            'url' => ImageStorage::r2Url($duongDan),
        ];
    }

    /**
     * Đường dẫn của file gốc trên chính disk đích (null nếu gốc không nằm trên disk nào).
     */
    protected static function duongDanGoc(array $tt): ?string
    {
        if ($tt['loai'] === static::LOAI_LOCAL) {
            return R2ImageMigrator::duongDanLocal($tt['url']);
        }

        if ($tt['loai'] === static::LOAI_R2) {
            return static::duongDanR2($tt['url']);
        }

        return null;
    }

    /**
     * Đọc bytes của ảnh gốc.
     */
    protected static function layNoiDung(array $tt): ?string
    {
        if ($tt['loai'] === static::LOAI_LOCAL) {
            $path = R2ImageMigrator::duongDanLocal($tt['url']);
            $disk = Storage::disk(ImageStorage::LOCAL_DISK);

            return ($path !== null && $disk->exists($path)) ? $disk->get($path) : null;
        }

        if ($tt['loai'] === static::LOAI_R2) {
            $path = static::duongDanR2($tt['url']);
            $disk = ImageStorage::r2Disk();

            return ($path !== null && $disk->exists($path)) ? $disk->get($path) : null;
        }

        $res = Http::timeout(30)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get($tt['url']);

        return $res->successful() ? $res->body() : null;
    }

    /**
     * Encode bytes ảnh sang WebP, chất lượng lấy từ option image_quality.
     */
    public static function encodeWebp(string $noiDung): ?string
    {
        if (! ImageStorage::supportsWebp()) {
            return null;
        }

        $img = Image::make($noiDung);

        try {
            $quality = (int) Option::get('image_quality', 85) ?: 85;

            return (string) $img->encode('webp', $quality);
        } finally {
            $img->destroy();
        }
    }

    /**
     * Ghi file, kèm ContentType/CacheControl khi đích là R2.
     *
     * Nhận cờ $laR2 từ dichCuaAnh() thay vì so sánh instance disk: hỏi lại
     * ImageStorage::r2Disk() ở đây sẽ ép cấu hình R2 cả khi ảnh chỉ nằm ở local,
     * và nổ ngay nếu site chưa khai báo R2.
     */
    protected static function ghi($disk, bool $laR2, string $duongDan, string $noiDung): bool
    {
        if ($laR2) {
            return ImageStorage::putToR2($duongDan, $noiDung, 'image/webp');
        }

        return (bool) $disk->put($duongDan, $noiDung);
    }

    public static function dinhDangBytes($bytes): string
    {
        return OrphanImageScanner::formatBytes((int) $bytes);
    }
}
