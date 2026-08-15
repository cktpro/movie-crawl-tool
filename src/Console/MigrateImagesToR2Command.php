<?php

namespace Movie\Crawler\MovieCrawler\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Movie\Crawler\MovieCrawler\ImageStorage;
use Movie\Crawler\MovieCrawler\R2ImageMigrator;

class MigrateImagesToR2Command extends Command
{
    /**
     * @var string
     */
    protected $signature = 'movie:plugins:movie-crawler:images-to-r2
        {--run : Chuyển thật. Không có cờ này thì chỉ liệt kê (dry-run)}
        {--limit=0 : Chỉ xử lý tối đa N phim (0 = tất cả)}
        {--force : Không hỏi xác nhận}';

    /**
     * @var string
     */
    protected $description = 'Kiểm tra ảnh của các phim hiện có, tải lên Cloudflare R2 rồi đổi link ảnh trong DB';

    public function handle()
    {
        if (! ImageStorage::r2Ready()) {
            $this->error('R2 chưa được cấu hình đủ (cần bucket, key, secret, endpoint).');
            $this->line('Điền ở Crawler > Options > tab "Cloudflare R2", hoặc đặt các biến R2_* trong .env.');

            return self::FAILURE;
        }

        if (ImageStorage::r2BaseUrl() === '') {
            $this->error('Thiếu "R2 URL" (domain công khai của bucket) — không có thì không dựng được link ảnh mới.');

            return self::FAILURE;
        }

        $this->info('Đang thống kê ảnh hiện tại...');
        $this->hienThiThongKe();

        $limit = max(0, (int) $this->option('limit'));
        $danhSach = R2ImageMigrator::quet($limit);

        if (empty($danhSach)) {
            $this->info('Không có ảnh nào cần chuyển — tất cả đã nằm trên R2 (hoặc phim không có ảnh).');

            return self::SUCCESS;
        }

        $soAnh = array_sum(array_map(fn ($p) => count($p['viec']), $danhSach));
        $this->info(sprintf('Cần chuyển: %d ảnh của %d phim.', $soAnh, count($danhSach)));

        if (! $this->option('run')) {
            $this->table(
                ['Phim', 'Cột', 'Loại', 'URL hiện tại', 'Sẽ thành'],
                collect(array_slice($danhSach, 0, 20))->flatMap(function ($p) {
                    return collect($p['viec'])->map(fn ($tt, $cot) => [
                        \Illuminate\Support\Str::limit($p['slug'], 28),
                        $cot,
                        $tt['loai'],
                        \Illuminate\Support\Str::limit($tt['url'], 42),
                        \Illuminate\Support\Str::limit(ImageStorage::r2Url(R2ImageMigrator::duongDanR2($p['slug'], $tt['url'])), 42),
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
            'Tải %d ảnh lên R2 và đổi link trong DB? (file local KHÔNG bị xoá)',
            $soAnh
        ))) {
            $this->line('Đã huỷ.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar(count($danhSach));
        $bar->start();

        $tong = ['doi' => 0, 'bo_qua' => 0, 'loi' => 0, 'bytes' => 0];
        $loiChiTiet = [];

        foreach ($danhSach as $phim) {
            $kq = R2ImageMigrator::chuyenMotPhim($phim, true);

            $tong['doi'] += count($kq['doi']);
            $tong['bo_qua'] += count($kq['bo_qua']);
            $tong['loi'] += count($kq['loi']);
            $tong['bytes'] += $kq['bytes'];

            foreach ($kq['loi'] as $cot => $msg) {
                $loiChiTiet[] = "{$phim['slug']} [{$cot}]: {$msg}";
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            'Xong: %d ảnh đã đổi link (%d ảnh vốn đã có sẵn trên R2), tải lên %s, %d lỗi.',
            $tong['doi'],
            $tong['bo_qua'],
            R2ImageMigrator::dinhDangBytes($tong['bytes']),
            $tong['loi']
        ));

        if ($loiChiTiet) {
            $this->warn('Các ảnh lỗi (giữ nguyên link cũ):');
            foreach (array_slice($loiChiTiet, 0, 20) as $d) {
                $this->line('  - ' . $d);
            }
            if (count($loiChiTiet) > 20) {
                $this->line('  ... và ' . (count($loiChiTiet) - 20) . ' lỗi nữa, xem log movie-crawler.');
            }
        }

        Log::channel('movie-crawler')->info('[images-to-r2] Chuyển ảnh lên R2', [
            'phim' => count($danhSach),
            'anh_doi_link' => $tong['doi'],
            'anh_da_co_san' => $tong['bo_qua'],
            'bytes' => $tong['bytes'],
            'loi' => $loiChiTiet,
        ]);

        $this->line('File ảnh ở local vẫn còn nguyên. Kiểm tra site hiển thị tốt rồi mới dọn bằng: '
            . 'php artisan movie:plugins:movie-crawler:cleanup-images');

        return self::SUCCESS;
    }

    protected function hienThiThongKe(): void
    {
        $tk = R2ImageMigrator::thongKe();
        $nhan = [
            R2ImageMigrator::LOAI_RONG => 'Không có ảnh',
            R2ImageMigrator::LOAI_R2 => 'Đã trên R2',
            R2ImageMigrator::LOAI_LOCAL => 'File local',
            R2ImageMigrator::LOAI_NGOAI => 'URL nguồn ngoài',
            R2ImageMigrator::LOAI_KHAC => 'Không nhận dạng',
        ];

        $this->table(
            array_merge(['Cột'], array_values($nhan)),
            collect($tk)->map(fn ($dem, $cot) => array_merge([$cot], array_map(fn ($k) => $dem[$k], array_keys($nhan))))->values()->all()
        );
    }
}
