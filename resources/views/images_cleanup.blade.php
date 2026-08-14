@extends(backpack_view('blank'))

@section('header')
    <section class="container-fluid">
        <h2>
            <span class="text-capitalize">Dọn ảnh rác</span>
            <small>Tìm thư mục ảnh đã crawl về nhưng không còn phim nào tham chiếu (phim đã xoá / đổi slug)</small>
        </h2>
    </section>
@endsection

@section('content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <form method="GET" action="{{ backpack_url('plugin/ophim-crawler/images-cleanup') }}" class="row align-items-end">
                    <input type="hidden" name="scan" value="1">
                    <div class="form-group col-md-4">
                        <label>Nơi lưu ảnh cần quét</label>
                        @foreach ($availableDisks as $key => $label)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="disks[]" value="{{ $key }}"
                                    id="disk-{{ $key }}"
                                    {{ in_array($key, $disks) ? 'checked' : '' }}>
                                <label class="form-check-label" for="disk-{{ $key }}">{{ $label }}</label>
                            </div>
                        @endforeach
                    </div>
                    <div class="form-group col-md-3">
                        <label for="min_age">Chỉ coi là "an toàn xoá" nếu không đổi trong (giờ)</label>
                        <input type="number" min="0" class="form-control" id="min_age" name="min_age" value="{{ $minAge }}">
                        <small class="text-muted">Tránh xoá nhầm ảnh vừa crawl xong nhưng phim chưa kịp lưu vào DB.</small>
                    </div>
                    <div class="form-group col-md-3">
                        <button type="submit" class="btn btn-primary"><i class="la la-search"></i> Quét ảnh rác</button>
                    </div>
                </form>
            </div>
        </div>

        @if ($scanned)
            <div class="card">
                <div class="card-body">
                    @if (empty($orphans))
                        <div class="alert alert-success mb-0">Không tìm thấy thư mục ảnh mồ côi nào trên: {{ implode(', ', $disks) }}.</div>
                    @else
                        <div class="alert alert-warning">
                            Tìm thấy <b>{{ count($orphans) }}</b> thư mục, tổng
                            <b>{{ \Ophim\Crawler\OphimCrawler\OrphanImageScanner::formatBytes($totalSize) }}</b>.
                            Thư mục đánh dấu <b>"Chưa (mới)"</b> nghĩa là mới sửa gần đây hơn ngưỡng an toàn ở trên —
                            mặc định sẽ <u>không</u> bị xoá trừ khi bạn tick "Buộc xoá cả mục chưa an toàn".
                        </div>

                        <form method="POST" action="{{ backpack_url('plugin/ophim-crawler/images-cleanup') }}"
                            onsubmit="return confirm('Xoá các thư mục đã chọn? Hành động này KHÔNG thể hoàn tác.');">
                            @csrf
                            <input type="hidden" name="min_age" value="{{ $minAge }}">

                            <div class="table-responsive">
                                <table class="table table-striped table-sm">
                                    <thead>
                                        <tr>
                                            <th><input type="checkbox" id="check-all"></th>
                                            <th>Disk</th>
                                            <th>Thư mục</th>
                                            <th>Số file</th>
                                            <th>Dung lượng</th>
                                            <th>Sửa lần cuối</th>
                                            <th>An toàn xoá?</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($orphans as $o)
                                            <tr>
                                                <td>
                                                    <input type="checkbox" class="item-check" name="items[]"
                                                        value="{{ $o['disk'] }}|{{ $o['folder'] }}"
                                                        {{ $o['safe_to_delete'] ? 'checked' : '' }}>
                                                </td>
                                                <td>{{ $o['disk'] }}</td>
                                                <td><code>{{ $o['path'] }}</code></td>
                                                <td>{{ $o['files'] }}</td>
                                                <td>{{ \Ophim\Crawler\OphimCrawler\OrphanImageScanner::formatBytes($o['size']) }}</td>
                                                <td>{{ $o['last_modified'] ? date('Y-m-d H:i', $o['last_modified']) : '(rỗng)' }}</td>
                                                <td>
                                                    @if ($o['safe_to_delete'])
                                                        <span class="badge badge-success">Có</span>
                                                    @else
                                                        <span class="badge badge-danger">Chưa (mới)</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="form-check mb-3">
                                <input type="checkbox" class="form-check-input" id="force" name="force" value="1">
                                <label class="form-check-label text-danger" for="force">
                                    Buộc xoá cả mục "Chưa (mới)" đã tick (bỏ qua ngưỡng an toàn)
                                </label>
                            </div>

                            <button type="submit" class="btn btn-danger">
                                <i class="la la-trash"></i> Xoá các thư mục đã chọn
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
@endsection

@section('after_scripts')
<script>
    document.getElementById('check-all')?.addEventListener('change', function (e) {
        document.querySelectorAll('.item-check').forEach(function (el) {
            el.checked = e.target.checked;
        });
    });
</script>
@endsection
