<?php

namespace Movie\Crawler\MovieCrawler\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Movie\Crawler\MovieCrawler\ImageStorage;
use Movie\Crawler\MovieCrawler\R2ImageMigrator;
use Prologue\Alerts\Facades\Alert;

/**
 * Trang admin: kiểm tra ảnh của các phim hiện có, tải lên Cloudflare R2 rồi đổi link.
 *
 * Xử lý theo lô (mặc định 50 phim mỗi lần bấm) thay vì chạy hết một phát: tải ảnh là
 * việc chậm và đi qua mạng, gom 700+ phim vào một request sẽ chạm timeout của PHP/web
 * server. Bấm lại nhiều lần cho tới khi hết — công cụ chạy lại được, ảnh đã lên R2 sẽ
 * tự bị bỏ qua.
 */
class ImageR2Controller extends Controller
{
    const MOI_LO = 50;

    public function index(Request $request)
    {
        return view('movie-crawler::images_r2', $this->duLieuTrang($request));
    }

    public function migrate(Request $request)
    {
        if (! ImageStorage::r2Ready()) {
            Alert::error('R2 chưa được cấu hình đủ (cần bucket, key, secret, endpoint). Điền ở tab Cloudflare R2 trong Options.')->flash();

            return back();
        }

        if (ImageStorage::r2BaseUrl() === '') {
            Alert::error('Thiếu "R2 URL" (domain công khai của bucket) — không có thì không dựng được link ảnh mới.')->flash();

            return back();
        }

        $soLuong = max(1, min(500, (int) $request->input('so_luong', static::MOI_LO)));
        $danhSach = R2ImageMigrator::quet($soLuong);

        if (empty($danhSach)) {
            Alert::success('Không còn ảnh nào cần chuyển — tất cả đã nằm trên R2.')->flash();

            return back();
        }

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
        }

        Log::channel('movie-crawler')->info('[images-to-r2] Chuyển ảnh lên R2 qua admin', [
            'user' => $request->user() ? $request->user()->id : null,
            'phim' => count($danhSach),
            'anh_doi_link' => $tong['doi'],
            'anh_da_co_san' => $tong['bo_qua'],
            'bytes' => $tong['bytes'],
            'loi' => $loiChiTiet,
        ]);

        $thongBao = sprintf(
            'Đã xử lý %d phim: %d ảnh đổi link (%d ảnh vốn đã có trên R2), tải lên %s.',
            count($danhSach),
            $tong['doi'],
            $tong['bo_qua'],
            R2ImageMigrator::dinhDangBytes($tong['bytes'])
        );

        if ($tong['loi'] > 0) {
            $thongBao .= ' ' . $tong['loi'] . ' ảnh lỗi, giữ nguyên link cũ (xem log movie-crawler).';
            Alert::warning($thongBao)->flash();
        } else {
            Alert::success($thongBao)->flash();
        }

        return back();
    }

    protected function duLieuTrang(Request $request): array
    {
        $xemTruoc = [];
        if ($request->boolean('scan')) {
            $xemTruoc = R2ImageMigrator::quet(30);
        }

        return [
            'title' => 'Chuyển ảnh lên R2',
            'breadcrumbs' => [
                trans('backpack::crud.admin') => backpack_url('dashboard'),
                'Chuyển ảnh lên R2' => false,
            ],
            'thongKe' => R2ImageMigrator::thongKe(),
            'r2San' => ImageStorage::r2Ready(),
            'r2Url' => ImageStorage::r2BaseUrl(),
            'daQuet' => $request->boolean('scan'),
            'xemTruoc' => $xemTruoc,
            'moiLo' => static::MOI_LO,
        ];
    }
}
