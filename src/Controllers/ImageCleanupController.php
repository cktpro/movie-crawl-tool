<?php

namespace Movie\Crawler\MovieCrawler\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Movie\Crawler\MovieCrawler\ImageStorage;
use Movie\Crawler\MovieCrawler\OrphanImageScanner;
use Prologue\Alerts\Facades\Alert;

/**
 * Trang admin: tìm & xoá các thư mục "images/{slug}" đã crawl về nhưng không
 * còn phim nào tham chiếu (phim đã bị xoá, hoặc slug phim đã đổi).
 *
 * Chỉ quét khi có bấm nút (GET ?scan=1) — tránh việc mở trang là tự động
 * liệt kê toàn bộ ảnh trên site mỗi lần, tốn thời gian với site nhiều phim.
 */
class ImageCleanupController extends Controller
{
    public function index(Request $request)
    {
        $disks = array_filter((array) $request->input('disks', []));
        if (empty($disks)) {
            $disks = $this->defaultDisks();
        }

        $minAge = max(0, (int) $request->input('min_age', 24));

        $orphans = [];
        if ($request->boolean('scan')) {
            foreach ($disks as $disk) {
                $orphans = array_merge($orphans, OrphanImageScanner::scan($disk, $minAge));
            }
        }

        return view('movie-crawler::images_cleanup', [
            'title' => 'Dọn ảnh rác',
            'breadcrumbs' => [
                trans('backpack::crud.admin') => backpack_url('dashboard'),
                'Dọn ảnh rác' => false,
            ],
            'scanned' => $request->boolean('scan'),
            'disks' => $disks,
            'minAge' => $minAge,
            'availableDisks' => $this->availableDisks(),
            'orphans' => $orphans,
            'totalSize' => array_sum(array_column($orphans, 'size')),
        ]);
    }

    public function destroy(Request $request)
    {
        $selected = collect((array) $request->input('items', []))
            ->map(function ($item) {
                // item dạng "disk|folder"
                [$disk, $folder] = array_pad(explode('|', $item, 2), 2, null);
                return ['disk' => $disk, 'folder' => $folder];
            })
            ->filter(fn ($i) => $i['disk'] && $i['folder']);

        if ($selected->isEmpty()) {
            Alert::warning('Chưa chọn thư mục nào để xoá.')->flash();
            return back();
        }

        $minAge = max(0, (int) $request->input('min_age', 24));
        $force = $request->boolean('force');

        // Quét lại ngay trước khi xoá để chỉ xoá đúng những thư mục vẫn còn mồ côi
        // tại thời điểm này (chống trường hợp form cũ, hoặc phim vừa được crawl lại).
        $disksToScan = $selected->pluck('disk')->unique()->values()->all();
        $fresh = [];
        foreach ($disksToScan as $disk) {
            $fresh = array_merge($fresh, OrphanImageScanner::scan($disk, $minAge));
        }

        $wanted = $selected->map(fn ($i) => $i['disk'] . '|' . $i['folder'])->flip();

        $toDelete = array_values(array_filter($fresh, function ($o) use ($wanted, $force) {
            $key = $o['disk'] . '|' . $o['folder'];
            return isset($wanted[$key]) && ($force || $o['safe_to_delete']);
        }));

        $skipped = $selected->count() - count($toDelete);

        if (empty($toDelete)) {
            Alert::warning('Không còn thư mục nào đủ điều kiện xoá (có thể đã bị xoá trước đó, hoặc chưa đủ "an toàn" — dùng tuỳ chọn buộc xoá nếu chắc chắn).')->flash();
            return back();
        }

        $result = OrphanImageScanner::delete($toDelete);

        \Illuminate\Support\Facades\Log::channel('movie-crawler')->info('[images-cleanup] Xoá thư mục ảnh mồ côi qua admin', [
            'user' => $request->user() ? $request->user()->id : null,
            'count' => count($result['deleted']),
            'freed_bytes' => $result['freed_bytes'],
            'paths' => $result['deleted'],
        ]);

        $message = sprintf(
            'Đã xoá %d thư mục, giải phóng %s.',
            count($result['deleted']),
            OrphanImageScanner::formatBytes($result['freed_bytes'])
        );
        if ($skipped > 0) {
            $message .= " Bỏ qua {$skipped} thư mục chưa đủ an toàn.";
        }

        Alert::success($message)->flash();

        return back();
    }

    protected function defaultDisks(): array
    {
        $disks = [ImageStorage::LOCAL_DISK];

        $r2Config = ImageStorage::r2Config();
        if (!empty($r2Config['bucket']) && !empty($r2Config['key'])) {
            $disks[] = ImageStorage::R2_DISK;
        }

        return $disks;
    }

    protected function availableDisks(): array
    {
        return [
            ImageStorage::LOCAL_DISK => 'Local (storage/app/public)',
            ImageStorage::R2_DISK => 'Cloudflare R2',
        ];
    }
}
