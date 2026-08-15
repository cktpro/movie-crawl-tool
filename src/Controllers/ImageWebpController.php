<?php

namespace Movie\Crawler\MovieCrawler\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Movie\Crawler\MovieCrawler\ImageStorage;
use Movie\Crawler\MovieCrawler\Option;
use Movie\Crawler\MovieCrawler\WebpConverter;
use Prologue\Alerts\Facades\Alert;

/**
 * Trang admin: chuyển ảnh của các phim hiện có sang WebP rồi đổi link trong DB.
 *
 * Xử lý theo lô (mặc định 50 phim mỗi lần bấm) giống trang chuyển ảnh lên R2: encode
 * ảnh tốn CPU, gom cả nghìn phim vào một request sẽ chạm timeout. Bấm lại nhiều lần
 * cho tới khi hết — ảnh đã có bản WebP sẽ tự bị bỏ qua.
 */
class ImageWebpController extends Controller
{
    const MOI_LO = 50;

    public function index(Request $request)
    {
        return view('movie-crawler::images_webp', $this->duLieuTrang($request));
    }

    public function convert(Request $request)
    {
        if (! ImageStorage::supportsWebp()) {
            Alert::error('PHP trên máy này chưa encode được WebP (cần GD có imagewebp hoặc Imagick hỗ trợ WEBP).')->flash();

            return back();
        }

        $soLuong = max(1, min(500, (int) $request->input('so_luong', static::MOI_LO)));
        $xoaGoc = $request->boolean('xoa_goc');
        $kemNgoai = $request->boolean('kem_ngoai');

        $danhSach = WebpConverter::quet($soLuong, $kemNgoai);

        if (empty($danhSach)) {
            Alert::success('Không còn ảnh nào cần chuyển — tất cả đã là WebP.')->flash();

            return back();
        }

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
        }

        Log::channel('movie-crawler')->info('[images-to-webp] Chuyển ảnh sang WebP qua admin', [
            'user' => $request->user() ? $request->user()->id : null,
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

        $thongBao = sprintf(
            'Đã xử lý %d phim: %d ảnh đổi link (%d ảnh vốn đã có bản WebP), %s → %s%s.',
            count($danhSach),
            $tong['doi'],
            $tong['bo_qua'],
            WebpConverter::dinhDangBytes($tong['truoc']),
            WebpConverter::dinhDangBytes($tong['sau']),
            $tong['truoc'] > 0
                ? sprintf(' (giảm %d%%)', (int) round(100 - $tong['sau'] / $tong['truoc'] * 100))
                : ''
        );

        if ($tong['xoa'] > 0) {
            $thongBao .= ' Đã xoá ' . $tong['xoa'] . ' file gốc.';
        }

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
        $kemNgoai = $request->boolean('kem_ngoai');

        $xemTruoc = [];
        if ($request->boolean('scan')) {
            $xemTruoc = WebpConverter::quet(30, $kemNgoai);
        }

        return [
            'title' => 'Chuyển ảnh sang WebP',
            'breadcrumbs' => [
                trans('backpack::crud.admin') => backpack_url('dashboard'),
                'Chuyển ảnh sang WebP' => false,
            ],
            'thongKe' => WebpConverter::thongKe(),
            'hoTroWebp' => ImageStorage::supportsWebp(),
            'chatLuong' => (int) Option::get('image_quality', 85) ?: 85,
            'dinhDangCrawl' => Option::get('image_format', 'original'),
            'daQuet' => $request->boolean('scan'),
            'kemNgoai' => $kemNgoai,
            'xemTruoc' => $xemTruoc,
            'moiLo' => static::MOI_LO,
        ];
    }
}
