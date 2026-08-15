<?php

namespace Movie\Crawler\MovieCrawler;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Movie\Core\Models\Movie;

/**
 * Chuyển ảnh của các phim đang có sang Cloudflare R2 rồi đổi luôn link trong DB.
 *
 * Dùng cho trường hợp site đã chạy một thời gian với ảnh lưu ở local (hoặc còn trỏ
 * thẳng sang ảnh của nguồn crawl) và nay muốn dồn hết lên R2. Khác với luồng crawl —
 * chỗ đó chỉ xử lý ảnh của phim đang được crawl tại thời điểm đó.
 *
 * Nguyên tắc:
 * - KHÔNG xoá file local. Chỉ tải lên R2 và đổi link; muốn dọn local thì dùng lệnh
 *   cleanup-images sau khi đã kiểm tra ảnh trên R2 hiển thị tốt.
 * - Chạy lại được nhiều lần: ảnh đã nằm trên R2 thì bỏ qua, object đã tồn tại trên
 *   bucket thì không tải lên lại mà chỉ đổi link.
 * - Đổi link bằng query builder chứ không qua model: tránh chạm vào updated_at.
 *   Cập nhật hàng loạt 700+ phim mà đụng updated_at sẽ khiến toàn bộ phim trông như
 *   vừa cập nhật, làm hỏng thứ tự "phim mới cập nhật" ngoài trang chủ.
 */
class R2ImageMigrator
{
    /** Các cột ảnh trong bảng movies */
    const COT_ANH = ['thumb_url', 'poster_url'];

    /** Phân loại một URL ảnh */
    const LOAI_RONG = 'rong';      // không có ảnh
    const LOAI_R2 = 'r2';          // đã nằm trên R2
    const LOAI_LOCAL = 'local';    // file trên disk public của site
    const LOAI_NGOAI = 'ngoai';    // URL của nguồn ngoài, phải tải về
    const LOAI_KHAC = 'khac';      // không nhận dạng được (vd tên file trần)

    /**
     * Phân loại một URL ảnh để biết phải làm gì với nó.
     */
    public static function phanLoai(?string $url): string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return static::LOAI_RONG;
        }

        $r2Base = ImageStorage::r2BaseUrl();
        if ($r2Base !== '' && str_starts_with($url, $r2Base . '/')) {
            return static::LOAI_R2;
        }

        if (static::duongDanLocal($url) !== null) {
            return static::LOAI_LOCAL;
        }

        if (preg_match('~^(https?:)?//~i', $url)) {
            return static::LOAI_NGOAI;
        }

        return static::LOAI_KHAC;
    }

    /**
     * Nếu URL trỏ tới file trên disk public của chính site thì trả về đường dẫn
     * tương đối trong disk đó, ngược lại trả null.
     *
     * Bắt cả hai dạng đang có trong DB:
     *   /storage/images/abc/abc-thumb.jpg
     *   https://hoathinhhay.org/storage/images/abc/abc-thumb.jpg
     */
    public static function duongDanLocal(string $url): ?string
    {
        $url = strtok($url, '?');

        // dạng tuyệt đối cùng domain -> cắt lấy phần path
        if (preg_match('~^(https?:)?//~i', $url)) {
            $path = parse_url($url, PHP_URL_PATH);
            $host = parse_url($url, PHP_URL_HOST);
            $hostSite = parse_url((string) config('app.url'), PHP_URL_HOST);

            if (! $path || ! $host || ! $hostSite || strcasecmp($host, $hostSite) !== 0) {
                return null;
            }

            $url = $path;
        }

        if (! str_starts_with($url, '/storage/')) {
            return null;
        }

        return ltrim(substr($url, strlen('/storage/')), '/');
    }

    /**
     * Đếm số phim theo từng loại URL — dùng cho màn hình tổng quan, không tải gì.
     */
    public static function thongKe(): array
    {
        $dem = [];
        foreach (static::COT_ANH as $cot) {
            $dem[$cot] = [
                static::LOAI_RONG => 0,
                static::LOAI_R2 => 0,
                static::LOAI_LOCAL => 0,
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
     * Danh sách phim còn ảnh chưa nằm trên R2.
     *
     * @param  int  $limit  0 = không giới hạn
     */
    public static function quet(int $limit = 0): array
    {
        $ketQua = [];

        Movie::select(array_merge(['id', 'slug', 'name'], static::COT_ANH))
            ->orderBy('id')
            ->chunkById(500, function ($phims) use (&$ketQua, $limit) {
                foreach ($phims as $phim) {
                    $viec = [];
                    foreach (static::COT_ANH as $cot) {
                        $loai = static::phanLoai($phim->{$cot});
                        if (in_array($loai, [static::LOAI_LOCAL, static::LOAI_NGOAI], true)) {
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
     * Chuyển ảnh của một phim lên R2.
     *
     * @param  bool  $thatSu  false = chỉ mô phỏng, không tải lên và không đổi DB
     * @return array{doi: array, bo_qua: array, loi: array, bytes: int}
     */
    public static function chuyenMotPhim(array $phim, bool $thatSu = false): array
    {
        $doi = [];
        $boQua = [];
        $loi = [];
        $bytes = 0;
        $capNhat = [];

        foreach ($phim['viec'] as $cot => $tt) {
            try {
                $duongDan = static::duongDanR2($phim['slug'], $tt['url']);
                $urlMoi = ImageStorage::r2Url($duongDan);

                if (! $thatSu) {
                    $doi[$cot] = ['tu' => $tt['url'], 'den' => $urlMoi, 'path' => $duongDan];
                    continue;
                }

                // Object đã có sẵn trên bucket (lần chạy trước bị gián đoạn giữa chừng)
                // thì không tải lên lại, chỉ cần đổi link.
                if (ImageStorage::r2Disk()->exists($duongDan)) {
                    $boQua[$cot] = 'đã có trên R2';
                } else {
                    $noiDung = static::layNoiDung($tt);
                    if ($noiDung === null || $noiDung === '') {
                        $loi[$cot] = 'không đọc được ảnh nguồn';
                        continue;
                    }

                    $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($noiDung) ?: null;
                    if (! ImageStorage::putToR2($duongDan, $noiDung, $mime)) {
                        $loi[$cot] = 'ghi lên R2 thất bại';
                        continue;
                    }

                    $bytes += strlen($noiDung);
                }

                $capNhat[$cot] = $urlMoi;
                $doi[$cot] = ['tu' => $tt['url'], 'den' => $urlMoi, 'path' => $duongDan];
            } catch (\Throwable $e) {
                $loi[$cot] = $e->getMessage();
            }
        }

        // Ghi bằng query builder để không đụng updated_at và không kích hoạt event.
        if ($thatSu && $capNhat) {
            DB::table('movies')->where('id', $phim['id'])->update($capNhat);
        }

        return ['doi' => $doi, 'bo_qua' => $boQua, 'loi' => $loi, 'bytes' => $bytes];
    }

    /**
     * Đường dẫn đích trên R2, giữ đúng quy ước images/{slug}/{tên file} như lúc crawl.
     */
    public static function duongDanR2(string $slug, string $url): string
    {
        $sach = strtok($url, '?');
        $ten = basename((string) $sach);

        if ($ten === '' || $ten === '/' || ! str_contains($ten, '.')) {
            $ten = $slug . '.jpg';
        }

        return 'images/' . $slug . '/' . $ten;
    }

    /**
     * Đọc bytes của ảnh nguồn: file local đọc thẳng, URL ngoài thì tải về.
     */
    protected static function layNoiDung(array $tt): ?string
    {
        if ($tt['loai'] === static::LOAI_LOCAL) {
            $path = static::duongDanLocal($tt['url']);
            $disk = Storage::disk(ImageStorage::LOCAL_DISK);

            return ($path !== null && $disk->exists($path)) ? $disk->get($path) : null;
        }

        $res = Http::timeout(30)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get($tt['url']);

        return $res->successful() ? $res->body() : null;
    }

    public static function dinhDangBytes($bytes): string
    {
        return OrphanImageScanner::formatBytes($bytes);
    }
}
