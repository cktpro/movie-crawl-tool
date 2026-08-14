<?php

namespace Ophim\Crawler\OphimCrawler\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Ophim\Crawler\OphimCrawler\ImageStorage;
use Ophim\Crawler\OphimCrawler\OrphanImageScanner;

class CleanupOrphanImagesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ophim:plugins:ophim-crawler:cleanup-images
        {--disk=* : local, r2, hoặc cả hai (mặc định: quét local, và quét thêm r2 nếu đã cấu hình)}
        {--delete : Xoá thật các thư mục mồ côi thay vì chỉ liệt kê}
        {--force : Không hỏi xác nhận, và xoá cả thư mục "chưa an toàn" (mới sửa gần đây)}
        {--min-age=24 : Số giờ tối thiểu kể từ lần sửa file cuối mới coi là an toàn để xoá}';

    /**
     * @var string
     */
    protected $description = 'Tìm (và tuỳ chọn xoá) các thư mục ảnh đã crawl về nhưng không còn phim nào tham chiếu tới (phim đã bị xoá / đổi slug)';

    public function handle()
    {
        $disks = $this->resolveDisks();
        $minAge = (int) $this->option('min-age');

        $this->info('Đang quét: ' . implode(', ', $disks) . ' (ngưỡng an toàn: ' . $minAge . ' giờ)');

        $orphans = [];
        foreach ($disks as $disk) {
            $orphans = array_merge($orphans, OrphanImageScanner::scan($disk, $minAge));
        }

        if (empty($orphans)) {
            $this->info('Không tìm thấy thư mục ảnh mồ côi nào.');
            return self::SUCCESS;
        }

        $this->table(
            ['Disk', 'Thư mục', 'Số file', 'Dung lượng', 'Sửa lần cuối', 'An toàn xoá?'],
            collect($orphans)->map(fn ($o) => [
                $o['disk'],
                $o['folder'],
                $o['files'],
                OrphanImageScanner::formatBytes($o['size']),
                $o['last_modified'] ? date('Y-m-d H:i', $o['last_modified']) : '(rỗng)',
                $o['safe_to_delete'] ? 'Có' : 'Chưa (mới)',
            ])
        );

        $totalSize = array_sum(array_column($orphans, 'size'));
        $this->info(sprintf(
            'Tổng: %d thư mục, %s.',
            count($orphans),
            OrphanImageScanner::formatBytes($totalSize)
        ));

        if (!$this->option('delete')) {
            $this->line('Chỉ liệt kê (dry-run). Thêm --delete để xoá thật.');
            return self::SUCCESS;
        }

        $toDelete = $this->option('force')
            ? $orphans
            : array_values(array_filter($orphans, fn ($o) => $o['safe_to_delete']));

        $skipped = count($orphans) - count($toDelete);
        if ($skipped > 0) {
            $this->warn("Bỏ qua {$skipped} thư mục mới sửa gần đây (chưa qua ngưỡng an toàn {$minAge}h). Dùng --force nếu chắc chắn muốn xoá luôn.");
        }

        if (empty($toDelete)) {
            $this->info('Không còn thư mục nào đủ điều kiện xoá.');
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm(sprintf(
            'Xoá %d thư mục (%s)? Hành động này KHÔNG thể hoàn tác.',
            count($toDelete),
            OrphanImageScanner::formatBytes(array_sum(array_column($toDelete, 'size')))
        ))) {
            $this->line('Đã huỷ.');
            return self::SUCCESS;
        }

        $result = OrphanImageScanner::delete($toDelete);

        Log::channel('ophim-crawler')->info('[cleanup-images] Đã xoá thư mục ảnh mồ côi', [
            'count' => count($result['deleted']),
            'freed_bytes' => $result['freed_bytes'],
            'paths' => $result['deleted'],
        ]);

        $this->info(sprintf(
            'Đã xoá %d thư mục, giải phóng %s.',
            count($result['deleted']),
            OrphanImageScanner::formatBytes($result['freed_bytes'])
        ));

        return self::SUCCESS;
    }

    protected function resolveDisks(): array
    {
        $requested = array_filter((array) $this->option('disk'));

        if (!empty($requested)) {
            return collect($requested)
                ->flatMap(fn ($d) => explode(',', $d))
                ->map(fn ($d) => trim($d) === 'r2' ? ImageStorage::R2_DISK : ImageStorage::LOCAL_DISK)
                ->unique()
                ->values()
                ->all();
        }

        $disks = [ImageStorage::LOCAL_DISK];

        // Chỉ quét thêm R2 nếu có vẻ đã từng được cấu hình, để tránh lỗi kết nối
        // khi site chưa dùng R2 bao giờ.
        $r2Config = ImageStorage::r2Config();
        if (!empty($r2Config['bucket']) && !empty($r2Config['key'])) {
            $disks[] = ImageStorage::R2_DISK;
        }

        return $disks;
    }
}
