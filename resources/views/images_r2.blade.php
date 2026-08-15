@extends(backpack_view('blank'))

@php
    $nhanLoai = [
        \Movie\Crawler\MovieCrawler\R2ImageMigrator::LOAI_R2 => ['Đã trên R2', 'success'],
        \Movie\Crawler\MovieCrawler\R2ImageMigrator::LOAI_LOCAL => ['File local', 'warning'],
        \Movie\Crawler\MovieCrawler\R2ImageMigrator::LOAI_NGOAI => ['URL nguồn ngoài', 'warning'],
        \Movie\Crawler\MovieCrawler\R2ImageMigrator::LOAI_KHAC => ['Không nhận dạng', 'danger'],
        \Movie\Crawler\MovieCrawler\R2ImageMigrator::LOAI_RONG => ['Không có ảnh', 'secondary'],
    ];

    $canChuyen = 0;
    foreach ($thongKe as $dem) {
        $canChuyen += $dem[\Movie\Crawler\MovieCrawler\R2ImageMigrator::LOAI_LOCAL]
            + $dem[\Movie\Crawler\MovieCrawler\R2ImageMigrator::LOAI_NGOAI];
    }
@endphp

@section('header')
    <section class="container-fluid">
        <h2>
            <span class="text-capitalize">Chuyển ảnh lên R2</span>
            <small>Kiểm tra ảnh của các phim hiện có, tải lên Cloudflare R2 rồi đổi link ảnh trong DB</small>
        </h2>
    </section>
@endsection

@section('content')
<div class="row">
    <div class="col-12">

        @if (! $r2San)
            <div class="alert alert-danger">
                <b>R2 chưa được cấu hình đủ.</b> Cần đủ <code>bucket</code>, <code>key</code>,
                <code>secret</code>, <code>endpoint</code> — điền ở
                <a href="{{ backpack_url('plugin/movie-crawler/options') }}">Options &rsaquo; tab Cloudflare R2</a>,
                hoặc đặt các biến <code>R2_*</code> trong <code>.env</code>.
            </div>
        @elseif ($r2Url === '')
            <div class="alert alert-danger">
                Thiếu <b>R2 URL</b> (domain công khai của bucket). Không có giá trị này thì không dựng được
                link ảnh mới để lưu vào DB.
            </div>
        @else
            <div class="alert alert-info">
                Ảnh sẽ được tải lên bucket theo đường dẫn <code>images/{slug}/{tên file}</code> và link trong DB
                đổi thành <code>{{ $r2Url }}/images/...</code>
            </div>
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
                        Còn <b>{{ $canChuyen }}</b> ảnh chưa nằm trên R2.
                    @else
                        <span class="text-success">Tất cả ảnh đã nằm trên R2.</span>
                    @endif
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <a href="{{ backpack_url('plugin/movie-crawler/images-r2') }}?scan=1" class="btn btn-secondary">
                    <i class="la la-search"></i> Xem trước 30 ảnh sẽ chuyển
                </a>

                @if ($r2San && $r2Url !== '' && $canChuyen > 0)
                    <form method="POST" action="{{ backpack_url('plugin/movie-crawler/images-r2') }}"
                        class="d-inline-block ml-2"
                        onsubmit="return confirm('Tải ảnh lên R2 và đổi link trong DB? File ảnh ở local KHÔNG bị xoá.');">
                        @csrf
                        <div class="form-inline">
                            <label class="mr-2">Số phim mỗi lần</label>
                            <input type="number" name="so_luong" value="{{ $moiLo }}" min="1" max="500"
                                class="form-control mr-2" style="width:100px">
                            <button type="submit" class="btn btn-primary">
                                <i class="la la-cloud-upload"></i> Chuyển lô tiếp theo
                            </button>
                        </div>
                    </form>
                @endif

                <p class="text-muted mt-3 mb-0">
                    Xử lý theo lô vì tải ảnh đi qua mạng, gom hết vào một request sẽ chạm timeout.
                    Bấm lại nhiều lần cho tới khi hết — ảnh đã lên R2 sẽ tự bị bỏ qua.
                    <br>
                    Tương đương ở dòng lệnh:
                    <code>php artisan movie:plugins:movie-crawler:images-to-r2 --run</code>
                    (bỏ <code>--run</code> để chỉ liệt kê).
                    <br>
                    <b>File ảnh ở local không bị xoá.</b> Kiểm tra site hiển thị tốt rồi mới dọn bằng
                    <a href="{{ backpack_url('plugin/movie-crawler/images-cleanup') }}">Dọn ảnh rác</a>.
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
                                                <td>
                                                    <small>
                                                        {{ Str::limit(\Movie\Crawler\MovieCrawler\ImageStorage::r2Url(
                                                            \Movie\Crawler\MovieCrawler\R2ImageMigrator::duongDanR2($p['slug'], $tt['url'])
                                                        ), 60) }}
                                                    </small>
                                                </td>
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
