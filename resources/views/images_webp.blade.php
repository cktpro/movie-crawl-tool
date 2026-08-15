@extends(backpack_view('blank'))

@php
    use Movie\Crawler\MovieCrawler\WebpConverter;

    $nhanLoai = [
        WebpConverter::LOAI_WEBP => ['Đã là WebP', 'success'],
        WebpConverter::LOAI_LOCAL => ['File local', 'warning'],
        WebpConverter::LOAI_R2 => ['Trên R2', 'warning'],
        WebpConverter::LOAI_NGOAI => ['URL nguồn ngoài', 'secondary'],
        WebpConverter::LOAI_KHAC => ['Không nhận dạng', 'danger'],
        WebpConverter::LOAI_RONG => ['Không có ảnh', 'secondary'],
    ];

    $canChuyen = 0;
    $anhNgoai = 0;
    foreach ($thongKe as $dem) {
        $canChuyen += $dem[WebpConverter::LOAI_LOCAL] + $dem[WebpConverter::LOAI_R2];
        $anhNgoai += $dem[WebpConverter::LOAI_NGOAI];
    }
@endphp

@section('header')
    <section class="container-fluid">
        <h2>
            <span class="text-capitalize">Chuyển ảnh sang WebP</span>
            <small>Encode lại ảnh của các phim hiện có thành WebP rồi đổi link ảnh trong DB</small>
        </h2>
    </section>
@endsection

@section('content')
<div class="row">
    <div class="col-12">

        @if (! $hoTroWebp)
            <div class="alert alert-danger">
                <b>PHP trên máy này chưa encode được WebP.</b> Cần GD có <code>imagewebp()</code>
                hoặc Imagick hỗ trợ định dạng <code>WEBP</code>.
            </div>
        @else
            <div class="alert alert-info mb-2">
                Ảnh mới được ghi cạnh ảnh cũ, cùng thư mục, chỉ đổi phần đuôi thành <code>.webp</code>
                — chất lượng encode <b>{{ $chatLuong }}</b> (đổi ở
                <a href="{{ backpack_url('plugin/movie-crawler/options') }}">Options &rsaquo; Image Optimize &rsaquo; Chất lượng ảnh</a>).
            </div>

            @if ($dinhDangCrawl !== 'webp')
                <div class="alert alert-warning">
                    Option <b>Định dạng ảnh lưu</b> đang là <code>{{ $dinhDangCrawl }}</code>, nên ảnh crawl
                    về sau này vẫn ở định dạng gốc. Đổi thành <b>Chuyển sang WebP</b> ở
                    <a href="{{ backpack_url('plugin/movie-crawler/options') }}">Options</a>
                    nếu muốn ảnh mới cũng là WebP.
                </div>
            @endif
        @endif

        <div class="card">
            <div class="card-header"><b>Tình trạng ảnh hiện tại</b></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Cột</th>
                                @foreach ($nhanLoai as $loai => $nl)
                                    <th class="text-right">{{ $nl[0] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($thongKe as $cot => $dem)
                                <tr>
                                    <td><code>{{ $cot }}</code></td>
                                    @foreach ($nhanLoai as $loai => $nl)
                                        <td class="text-right">
                                            @if ($dem[$loai] > 0)
                                                <span class="badge badge-{{ $nl[1] }}">{{ $dem[$loai] }}</span>
                                            @else
                                                <span class="text-muted">0</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="mt-3 mb-0">
                    @if ($canChuyen > 0)
                        Còn <b>{{ $canChuyen }}</b> ảnh chưa phải WebP.
                    @else
                        <span class="text-success">Tất cả ảnh trong site đã là WebP.</span>
                    @endif
                    @if ($anhNgoai > 0)
                        Ngoài ra có <b>{{ $anhNgoai }}</b> ảnh còn trỏ sang nguồn ngoài — chỉ được xử lý
                        khi tích ô <i>kéo cả ảnh nguồn ngoài về</i>.
                    @endif
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <a href="{{ backpack_url('plugin/movie-crawler/images-webp') }}?scan=1{{ $kemNgoai ? '&kem_ngoai=1' : '' }}"
                    class="btn btn-secondary">
                    <i class="la la-search"></i> Xem trước 30 ảnh sẽ chuyển
                </a>

                @if ($hoTroWebp && ($canChuyen > 0 || $anhNgoai > 0))
                    <form method="POST" action="{{ backpack_url('plugin/movie-crawler/images-webp') }}"
                        class="d-inline-block ml-2"
                        onsubmit="return confirm('Encode ảnh sang WebP và đổi link trong DB?');">
                        @csrf
                        <div class="form-inline">
                            <label class="mr-2">Số phim mỗi lần</label>
                            <input type="number" name="so_luong" value="{{ $moiLo }}" min="1" max="500"
                                class="form-control mr-2" style="width:100px">

                            <div class="form-check mr-3">
                                <input class="form-check-input" type="checkbox" name="xoa_goc" value="1" id="xoa_goc">
                                <label class="form-check-label" for="xoa_goc">Xoá file gốc sau khi chuyển</label>
                            </div>

                            <div class="form-check mr-3">
                                <input class="form-check-input" type="checkbox" name="kem_ngoai" value="1" id="kem_ngoai"
                                    {{ $kemNgoai ? 'checked' : '' }}>
                                <label class="form-check-label" for="kem_ngoai">Kéo cả ảnh nguồn ngoài về</label>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="la la-compress"></i> Chuyển lô tiếp theo
                            </button>
                        </div>
                    </form>
                @endif

                <p class="text-muted mt-3 mb-0">
                    Xử lý theo lô vì encode ảnh tốn CPU, gom hết vào một request sẽ chạm timeout.
                    Bấm lại nhiều lần cho tới khi hết — ảnh đã có bản WebP sẽ tự bị bỏ qua.
                    <br>
                    Tương đương ở dòng lệnh:
                    <code>php artisan movie:plugins:movie-crawler:images-to-webp --run</code>
                    (bỏ <code>--run</code> để chỉ liệt kê, thêm <code>--delete-original</code> để xoá file gốc).
                    <br>
                    <b>Mặc định không xoá file gốc.</b> Nên chạy một lô giữ file gốc, kiểm tra ảnh hiển thị
                    ngoài site rồi mới tích ô xoá — hoặc dọn sau bằng
                    <a href="{{ backpack_url('plugin/movie-crawler/images-cleanup') }}">Dọn ảnh rác</a>
                    (chỉ dọn được thư mục ảnh của phim đã bị xoá, không dọn file gốc của phim còn sống).
                </p>
            </div>
        </div>

        @if ($daQuet)
            <div class="card">
                <div class="card-header"><b>Xem trước</b> (tối đa 30 phim)</div>
                <div class="card-body">
                    @if (empty($xemTruoc))
                        <div class="alert alert-success mb-0">Không còn ảnh nào cần chuyển.</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>Phim</th>
                                        <th>Cột</th>
                                        <th>Loại</th>
                                        <th>URL hiện tại</th>
                                        <th>Sẽ thành</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($xemTruoc as $p)
                                        @foreach ($p['viec'] as $cot => $tt)
                                            <tr>
                                                <td>{{ $p['slug'] }}</td>
                                                <td><code>{{ $cot }}</code></td>
                                                <td>
                                                    <span class="badge badge-{{ $nhanLoai[$tt['loai']][1] }}">
                                                        {{ $nhanLoai[$tt['loai']][0] }}
                                                    </span>
                                                </td>
                                                <td><small>{{ Str::limit($tt['url'], 60) }}</small></td>
                                                <td><small>{{ Str::limit(WebpConverter::doiDuoiWebp(strtok($tt['url'], '?')), 60) }}</small></td>
                                            </tr>
                                        @endforeach
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        @endif

    </div>
</div>
@endsection
