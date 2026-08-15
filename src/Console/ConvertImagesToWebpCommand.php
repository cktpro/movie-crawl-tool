<?php

namespace Movie\Crawler\MovieCrawler\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Movie\Crawler\MovieCrawler\ImageStorage;
use Movie\Crawler\MovieCrawler\WebpConverter;

class ConvertImagesToWebpCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'movie:plugins:movie-crawler:images-to-webp
        {--run : Chuyển thật. Không có cờ này thì chỉ liệt kê (dry-run)}
        {--limit=0 : Chỉ xử lý tối đa N phim (0 = tất cả)}
        {--delete-original : Xoá file gốc sau khi ghi bản WebP thành công}
        {--with-external : Kéo cả ảnh còn trỏ sang nguồn ngoài về rồi chuyển}
        {--force : Không hỏi xác nhận}';

    /**
     * @var string
     */
    protected $description = 'Encode ảnh của các phim hiện có sang WebP rồi đổi link ảnh trong DB';

    public function handle()
    {
        if (! ImageStorage::supportsWebp()) {
            $this->error('PHP trên máy này chưa encode được WebP (cần GD có imagewebp hoặc Imagick hỗ trợ WEBP).');

            return self::FAILURE;
        }

        $this->info('Đang thống kê ảnh hiện tại...');
        $this->hienThiThongKe();

        $limit = max(0, (int) $this->option('limit'));
        $kemNgoai = (bool) $this->option('with-external');
        $xoaGoc = (bool) $this->option('delete-original');

        $danhSach = WebpConverter::quet($limit, $kemNgoai);

        if (empty($danhSach)) {
            $this->info('Không có ảnh nào cần chuyển — tất cả đã là WebP (hoặc phim không có ảnh).');

            return self::SUCCESS;
        }

        $soAnh = array_sum(array_map(fn ($p) => count($p['viec']), $danhSach));
        $this->info(sprintf('Cần chuyển: %d ảnh của %d phim.', $soAnh, count($danhSach)));

        if (! $this->option('run')) {
            $this->table(
                ['Phim', 'Cột', 'Loại', 'URL hiện tại', 'Sẽ thành'],
                collect(array_slice($danhSach, 0, 20))->flatMap(function ($p) {
                    return collect($p['viec'])->map(fn ($tt, $cot) => [
                        Str::limit($p['slug'], 28),
                        $cot,
                        $tt['loai'],
                        Str::limit($tt['url'], 42),
                        Str::limit(WebpConverter::doiDuoiWebp(strtok($tt['url'], '?')), 42),
                    ])->values();
                })->all()
            );

            if (count($danhSach) > 20) {
                $this->line('... và ' . (count($danhSach) - 20) . ' phim nữa.');
            }

            $this->line('Chỉ liệt kê (dry-run). Thêm --run để chuyển thật.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(sprintf(
            'Encode %d ảnh sang WebP và đổi link trong DB? (%s)',
            $soAnh,
            $xoaGoc ? 'XOÁ file gốc sau khi chuyển' : 'file gốc được giữ lại'
        ))) {
            $this->line('Đã huỷ.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar(count($danhSach));
        $bar->start();

        $tong = ['doi' => 0, 'bo_qua' => 0, 'loi' => 0, 'truoc' => 0, 'sau' => 0, 'xoa' => 0];
        $loiChiTiet = [];

        foreach ($danhSach as $phim) {
            $kq = WebpConverter::chuyenMotPhim($phim, true, $xoaGoc);

            $tong['doi'] += count($kq['doi']);
            $tong['bo_qua'] += count($kq['bo_qua']);
            $tong['loi'] += count($kq['loi']);
            $tong['truoc'] += $kq['bytes_truoc'];
            $tong['sau'] += $kq['bytes_sau'];
            $tong['xoa'] += $kq['da_xoa'];

            foreach ($kq['loi'] as $cot => $msg) {
                $loiChiTiet[] = "{$phim['slug']} [{$cot}]: {$msg}";
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            'Xong: %d ảnh đã đổi link (%d ảnh vốn đã có bản WebP), %s → %s%s, %d lỗi.',
            $tong['doi'],
            $tong['bo_qua'],
            WebpConverter::dinhDangBytes($tong['truoc']),
            WebpConverter::dinhDangBytes($tong['sau']),
            $tong['truoc'] > 0 ? sprintf(' (giảm %d%%)', (int) round(100 - $tong['sau'] / $tong['truoc'] * 100)) : '',
            $tong['loi']
        ));

        if ($tong['xoa'] > 0) {
            $this->line('Đã xoá ' . $tong['xoa'] . ' file gốc.');
        }

        if ($loiChiTiet) {
            $this->warn('Các ảnh lỗi (giữ nguyên link cũ):');
            foreach (array_slice($loiChiTiet, 0, 20) as $d) {
                $this->line('  - ' . $d);
            }
            if (count($loiChiTiet) > 20) {
                $this->line('  ... và ' . (count($loiChiTiet) - 20) . ' lỗi nữa, xem log movie-crawler.');
            }
        }

        Log::channel('movie-crawler')->info('[images-to-webp] Chuyển ảnh sang WebP', [
            'phim' => count($danhSach),
            'anh_doi_link' => $tong['doi'],
            'anh_da_co_san' => $tong['bo_qua'],
            'bytes_truoc' => $tong['truoc'],
            'bytes_sau' => $tong['sau'],
            'file_goc_da_xoa' => $tong['xoa'],
            'xoa_goc' => $xoaGoc,
            'kem_nguon_ngoai' => $kemNgoai,
            'loi' => $loiChiTiet,
        ]);

        if (! $xoaGoc) {
            $this->line('File gốc vẫn còn nguyên. Kiểm tra site hiển thị tốt rồi chạy lại với --delete-original nếu muốn dọn.');
        }

        return self::SUCCESS;
    }

    protected function hienThiThongKe(): void
    {
        $tk = WebpConverter::thongKe();
        $nhan = [
            WebpConverter::LOAI_RONG => 'Không có ảnh',
            WebpConverter::LOAI_WEBP => 'Đã là WebP',
            WebpConverter::LOAI_LOCAL => 'File local',
            WebpConverter::LOAI_R2 => 'Trên R2',
            WebpConverter::LOAI_NGOAI => 'URL nguồn ngoài',
            WebpConverter::LOAI_KHAC => 'Không nhận dạng',
        ];

        $this->table(
            array_merge(['Cột'], array_values($nhan)),
            collect($tk)->map(fn ($dem, $cot) => array_merge([$cot], array_map(fn ($k) => $dem[$k], array_keys($nhan))))->values()->all()
        );
    }
}
