<?php
/**
 * 图床管理面板
 *
 * 由 admin/extending.php 在后台上下文中加载，
 * 可直接使用 $options / $user / $security / $request / $menu
 */

use TypechoPlugin\ImgBed\Action as ImgBedAction;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

include 'common.php';
include 'header.php';
include 'menu.php';

// 三个接口地址（由 Security 自动附加 _ 令牌）
$actionUpload = $security->getIndex('/action/imgbed?do=upload');
$actionList   = $security->getIndex('/action/imgbed?do=list');
$actionDelete = $security->getIndex('/action/imgbed?do=delete');
$maxSizeMB    = (int) max(1, \Widget\Options::alloc()->plugin('ImgBed')->maxSizeMB ?: 20);
?>

<main class="main">
    <div class="body container">
        <?php include 'page-title.php'; ?>
        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12 typecho-list">

                <!-- 上传区 -->
                <div class="typecho-page-title" style="margin-bottom:10px">
                    <h2 style="font-size:1.1em">上传图片</h2>
                </div>
                <div id="imgbed-drop" style="
                    border:2px dashed #b0b0b0; border-radius:6px; padding:34px 20px;
                    text-align:center; color:#666; background:#fafafa; cursor:pointer;
                    transition:border-color .2s, background .2s;">
                    <p style="font-size:14px; margin:0">
                        <strong>点击选择</strong> 或 <strong>拖拽</strong> 图片到此处，
                        也可直接 <strong>Ctrl+V 粘贴</strong>截图
                    </p>
                    <p style="font-size:12px; color:#999; margin:6px 0 0">
                        支持 jpg / png / gif / webp / bmp / svg / ico / avif，单张 ≤ <?php echo $maxSizeMB; ?>MB，可多选
                    </p>
                </div>
                <input id="imgbed-file" type="file" multiple
                       accept="image/*"
                       style="display:none" />

                <!-- 进度 -->
                <div id="imgbed-progress" style="display:none; margin-top:10px">
                    <div style="background:#eee; border-radius:4px; height:8px; overflow:hidden">
                        <div id="imgbed-bar" style="background:#467b96; height:100%; width:0%"></div>
                    </div>
                    <p id="imgbed-progress-text" style="font-size:12px; color:#999; margin:4px 0 0"></p>
                </div>

                <!-- 结果 -->
                <div id="imgbed-results" style="margin-top:12px"></div>

                <!-- 图库 -->
                <div class="typecho-page-title" style="margin:26px 0 10px">
                    <h2 style="font-size:1.1em">图片库 <span id="imgbed-count" style="font-size:12px;color:#999;font-weight:normal"></span></h2>
                </div>
                <div id="imgbed-grid"
                     style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:14px"></div>

                <!-- 复制成功提示 -->
                <div id="imgbed-toast" style="
                    position:fixed; bottom:30px; left:50%; transform:translateX(-50%);
                    background:rgba(0,0,0,.8); color:#fff; padding:8px 18px; border-radius:20px;
                    font-size:13px; display:none; z-index:9999"></div>
            </div>
        </div>
    </div>
</main>

<script>
(function () {
    var CFG = {
        upload: <?php echo json_encode($actionUpload); ?>,
        list:   <?php echo json_encode($actionList); ?>,
        del:    <?php echo json_encode($actionDelete); ?>
    };
    var drop    = document.getElementById('imgbed-drop');
    var fileIn  = document.getElementById('imgbed-file');
    var bar     = document.getElementById('imgbed-bar');
    var progBox = document.getElementById('imgbed-progress');
    var progTxt = document.getElementById('imgbed-progress-text');
    var results = document.getElementById('imgbed-results');
    var grid    = document.getElementById('imgbed-grid');
    var countEl = document.getElementById('imgbed-count');
    var toast   = document.getElementById('imgbed-toast');

    function showToast(msg) {
        toast.textContent = msg;
        toast.style.display = 'block';
        clearTimeout(showToast._t);
        showToast._t = setTimeout(function () { toast.style.display = 'none'; }, 1800);
    }

    function fmtSize(b) {
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
        return (b / 1048576).toFixed(2) + ' MB';
    }

    function copyText(text, tip) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function () { showToast(tip || '已复制'); });
        } else {
            var ta = document.createElement('textarea');
            ta.value = text; document.body.appendChild(ta);
            ta.select(); document.execCommand('copy');
            document.body.removeChild(ta); showToast(tip || '已复制');
        }
    }

    /* ---------- 上传 ---------- */
    function uploadFiles(files) {
        if (!files || !files.length) return;
        var fd = new FormData();
        for (var i = 0; i < files.length; i++) {
            if (/^image\//.test(files[i].type) || /\.(jpe?g|png|gif|webp|bmp|svg|ico|avif)$/i.test(files[i].name)) {
                fd.append('files[]', files[i]);
            }
        }
        if (![...fd.keys()].length) { showToast('没有可上传的图片'); return; }

        var xhr = new XMLHttpRequest();
        progBox.style.display = 'block';
        results.innerHTML = '';
        xhr.upload.addEventListener('progress', function (e) {
            if (e.lengthComputable) {
                var pct = Math.round(e.loaded / e.total * 100);
                bar.style.width = pct + '%';
                progTxt.textContent = '上传中 ' + pct + '%';
            }
        });
        xhr.addEventListener('load', function () {
            progBox.style.display = 'none'; bar.style.width = '0%';
            var data;
            try { data = JSON.parse(xhr.responseText); } catch (e) { showToast('响应异常'); return; }
            if (!data.ok) { showToast(data.msg || '上传失败'); return; }
            renderResults(data.items);
            loadList();
        });
        xhr.addEventListener('error', function () { progBox.style.display = 'none'; showToast('网络错误'); });
        xhr.open('POST', CFG.upload);
        xhr.send(fd);
    }

    function renderResults(items) {
        var html = '';
        items.forEach(function (it) {
            html += '<div class="imgbed-item" style="border:1px solid #e5e5e5;border-radius:6px;padding:10px;margin-bottom:8px;background:#fff">'
                + (it.ok
                    ? '<div style="display:flex;gap:10px;align-items:flex-start">'
                        + '<img src="' + it.url + '" style="width:64px;height:64px;object-fit:cover;border-radius:4px;border:1px solid #eee">'
                        + '<div style="flex:1;min-width:0">'
                        + '<div style="font-size:13px;font-weight:bold;word-break:break-all;color:#2e8b57">✔ ' + it.name + '</div>'
                        + '<div style="font-size:12px;color:#999;margin:2px 0">' + fmtSize(it.size) + '</div>'
                        + '<input class="imgbed-url" readonly value="' + it.url + '" style="width:100%;font-size:12px;padding:3px;border:1px solid #ddd;border-radius:3px;color:#555">'
                        + '</div></div>'
                        + '<div style="margin-top:8px">'
                        + btn('URL', it.url) + btn('Markdown', it.markdown) + btn('HTML', it.html) + btn('BBCode', it.bbcode)
                        + '</div>'
                    : '<div style="color:#c00;font-size:13px">✘ ' + it.name + ' — ' + (it.msg || '失败') + '</div>')
                + '</div>';
        });
        results.innerHTML = html;
        bindCopy();
        if (items.some(function (it) { return it.ok; })) showToast('上传完成');
    }

    function btn(label, text) {
        return '<button class="btn btn-xs btn-primary imgbed-copy" data-text="' + encodeURIComponent(text) + '" '
            + 'style="margin-right:6px;margin-top:4px">' + label + '</button>';
    }

    function bindCopy() {
        document.querySelectorAll('.imgbed-copy').forEach(function (b) {
            b.onclick = function () { copyText(decodeURIComponent(b.dataset.text), labelOf(b) + ' 已复制到剪贴板'); };
        });
        function labelOf(b) { return b.textContent; }
    }

    /* ---------- 图库 ---------- */
    function loadList() {
        fetch(CFG.list, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) return;
                countEl.textContent = '共 ' + data.items.length + ' 张';
                grid.innerHTML = data.items.map(function (it) {
                    return '<div class="imgbed-grid-item" style="border:1px solid #e5e5e5;border-radius:6px;overflow:hidden;background:#fff">'
                        + '<a href="' + it.url + '" target="_blank" style="display:block;height:130px;background:#f5f5f5 url(' + it.url + ') center/cover no-repeat"></a>'
                        + '<div style="padding:8px;font-size:12px">'
                        + '<div style="word-break:break-all;color:#666;margin-bottom:4px">' + it.name + '</div>'
                        + '<div style="color:#aaa;margin-bottom:6px">' + fmtSize(it.size) + ' · ' + new Date(it.mtime * 1000).toLocaleString() + '</div>'
                        + '<button class="btn btn-xs btn-primary imgbed-copy" data-text="' + encodeURIComponent(it.url) + '" style="margin-right:4px">URL</button>'
                        + '<button class="btn btn-xs imgbed-copy" data-text="' + encodeURIComponent('![](' + it.url + ')') + '" style="margin-right:4px">MD</button>'
                        + '<button class="btn btn-xs btn-danger imgbed-del" data-path="' + encodeURIComponent(it.path) + '">删除</button>'
                        + '</div></div>';
                }).join('');
                bindCopy();
                grid.querySelectorAll('.imgbed-del').forEach(function (b) {
                    b.onclick = function () {
                        if (!confirm('确定删除这张图片吗？已发布文章中的外链将失效。')) return;
                        fetch(CFG.del + '&path=' + encodeURIComponent(decodeURIComponent(b.dataset.path)), { credentials: 'same-origin' })
                            .then(function (r) { return r.json(); })
                            .then(function (d) {
                                showToast(d.msg || (d.ok ? '已删除' : '删除失败'));
                                if (d.ok) loadList();
                            });
                    };
                });
            });
    }

    /* ---------- 交互绑定 ---------- */
    drop.addEventListener('click', function () { fileIn.click(); });
    fileIn.addEventListener('change', function () { uploadFiles(fileIn.files); fileIn.value = ''; });

    ['dragenter', 'dragover'].forEach(function (ev) {
        drop.addEventListener(ev, function (e) {
            e.preventDefault();
            drop.style.borderColor = '#467b96'; drop.style.background = '#eef5f8';
        });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        drop.addEventListener(ev, function (e) {
            e.preventDefault();
            drop.style.borderColor = '#b0b0b0'; drop.style.background = '#fafafa';
        });
    });
    drop.addEventListener('drop', function (e) { uploadFiles(e.dataTransfer.files); });

    // Ctrl+V 粘贴截图
    document.addEventListener('paste', function (e) {
        var items = e.clipboardData && e.clipboardData.items;
        if (!items) return;
        var files = [];
        for (var i = 0; i < items.length; i++) {
            if (/^image\//.test(items[i].type)) files.push(items[i].getAsFile());
        }
        if (files.length) { e.preventDefault(); uploadFiles(files); }
    });

    loadList();
})();
</script>

<?php include 'footer.php'; ?>
