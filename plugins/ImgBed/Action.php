<?php
/**
 * 本地图床 - 上传/列表/删除 接口
 *
 * 路由: index.php/action/imgbed?do=upload|list|delete
 * 权限: 仅管理员；写操作需携带面板页下发的 CSRF token（_ 参数）
 */

namespace TypechoPlugin\ImgBed;

use Typecho\Widget;
use Widget\ActionInterface;
use Widget\Security;
use Widget\User;

defined('__TYPECHO_ROOT_DIR__') || exit;

class Action extends Widget implements ActionInterface
{
    public function action()
    {
        // 1) 登录 + 权限
        $user = User::alloc();
        if (!$user->hasLogin()) {
            $this->json(['ok' => false, 'msg' => '请先登录后台'], 403);
        }
        if ($user->group !== 'administrator') {
            $this->json(['ok' => false, 'msg' => '仅管理员可用'], 403);
        }

        // 2) CSRF：沿用 Typecho 标准约定（面板页经 Security::getIndex 生成的 _ 参数）
        Security::alloc()->protect();

        Plugin::ensureDir();

        switch ($this->request->get('do')) {
            case 'upload':
                $this->upload();
                break;
            case 'list':
                $this->list();
                break;
            case 'delete':
                $this->delete();
                break;
            default:
                $this->json(['ok' => false, 'msg' => '未知操作'], 400);
        }
    }

    /* ---------- upload ---------- */

    private function upload()
    {
        if (empty($_FILES['files'])) {
            $this->json(['ok' => false, 'msg' => '没有收到文件'], 400);
        }

        $files    = $_FILES['files'];
        $maxBytes = Plugin::maxBytes();
        $results  = [];

        // 兼容单文件/多文件两种表单结构
        $names  = (array) $files['name'];
        $types  = (array) $files['type'];
        $tmps   = (array) $files['tmp_name'];
        $errors = (array) $files['error'];
        $sizes  = (array) $files['size'];

        foreach ($names as $i => $name) {
            $name  = (string) $name;
            $error = (int) ($errors[$i] ?? UPLOAD_ERR_NO_FILE);

            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                $results[] = ['name' => $name, 'ok' => false, 'msg' => 'PHP 上传错误码 ' . $error];
                continue;
            }

            $tmp = (string) $tmps[$i];
            if (!is_uploaded_file($tmp)) {
                $results[] = ['name' => $name, 'ok' => false, 'msg' => '非法上传'];
                continue;
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!Plugin::extAllowed($ext)) {
                $results[] = ['name' => $name, 'ok' => false, 'msg' => '不支持的文件类型 .' . $ext];
                continue;
            }

            if ((int) $sizes[$i] > $maxBytes) {
                $results[] = [
                    'name' => $name, 'ok' => false,
                    'msg'  => '超过大小限制 ' . round($maxBytes / 1048576) . 'MB',
                ];
                continue;
            }

            // 位图类型必须能被解析（防伪装），svg 不经过 gd 解析故跳过
            if (!in_array($ext, ['svg'], true)) {
                $info = @getimagesize($tmp);
                if ($info === false) {
                    $results[] = ['name' => $name, 'ok' => false, 'msg' => '不是有效的图片文件'];
                    continue;
                }
            }

            $sub  = date('Ym');
            $dir  = Plugin::absDir() . '/' . $sub;
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $final = date('YmdHis') . '-' . substr(md5($name . uniqid('', true)), 0, 6) . '.' . $ext;
            $dest  = $dir . '/' . $final;

            if (!@move_uploaded_file($tmp, $dest)) {
                $results[] = ['name' => $name, 'ok' => false, 'msg' => '保存失败（检查目录写权限）'];
                continue;
            }
            @chmod($dest, 0644);

            $rel     = $sub . '/' . $final;
            $url     = Plugin::baseUrl() . '/' . $rel;
            $results[] = [
                'name'    => $name,
                'ok'      => true,
                'path'    => $rel,
                'url'     => $url,
                'markdown' => '![](' . $url . ')',
                'html'    => '<img src="' . $url . '" alt="' . htmlspecialchars($name, ENT_QUOTES) . '" />',
                'bbcode'  => '[img]' . $url . '[/img]',
                'size'    => (int) $sizes[$i],
            ];
        }

        if (empty($results)) {
            $this->json(['ok' => false, 'msg' => '没有可处理的文件'], 400);
        }
        $this->json(['ok' => true, 'items' => $results]);
    }

    /* ---------- list ---------- */

    private function list()
    {
        $items = self::scan();
        // 新的在前
        usort($items, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);
        $this->json(['ok' => true, 'items' => $items]);
    }

    /** 扫描存储目录（公开静态方法，供面板页直接复用） */
    public static function scan(): array
    {
        $root = Plugin::absDir();
        if (!is_dir($root)) {
            return [];
        }

        $items = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $ext = strtolower($f->getExtension());
            if (!Plugin::extAllowed($ext)) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
            $items[] = [
                'name'  => $f->getFilename(),
                'path'  => $rel,
                'url'   => Plugin::baseUrl() . '/' . $rel,
                'size'  => $f->getSize(),
                'mtime' => $f->getMTime(),
            ];
        }
        return $items;
    }

    /* ---------- delete ---------- */

    private function delete()
    {
        $rel = (string) $this->request->get('path', '');
        if ($rel === '') {
            $this->json(['ok' => false, 'msg' => '缺少 path 参数'], 400);
        }

        $root = realpath(Plugin::absDir());
        $file = realpath(Plugin::absDir() . '/' . $rel);

        // 路径穿越防护：目标必须落在存储目录内
        if ($root === false || $file === false || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0) {
            $this->json(['ok' => false, 'msg' => '非法路径'], 400);
        }

        if (!is_file($file) || !@unlink($file)) {
            $this->json(['ok' => false, 'msg' => '删除失败'], 500);
        }

        // 顺手清理空月份目录
        $parent = dirname($file);
        if ($parent !== $root && count(glob($parent . '/*')) === 0) {
            @rmdir($parent);
        }

        $this->json(['ok' => true, 'msg' => '已删除 ' . basename($file)]);
    }

    /* ---------- utils ---------- */

    private function json(array $data, int $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
