<?php
/**
 * 本地图床 (ImgBed)
 *
 * 在 Typecho 后台提供图片上传 / 外链复制 / 图片管理面板，
 * 图片存放在站点 usr/uploads/imgbed 目录，通过站点自身域名生成外链。
 *
 * @package ImgBed
 * @author lanrenbao
 * @version 1.0.0
 * @link https://kingstaple.com
 */

namespace TypechoPlugin\ImgBed;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Radio;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Plugin implements PluginInterface
{
    /** 相对站点根目录的存储路径 */
    const REL_DIR = '/usr/uploads/imgbed';

    /** 允许的扩展名 */
    const ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico', 'avif'];

    public static function activate()
    {
        Helper::addAction('imgbed', 'ImgBed_Action');
        Helper::addPanel(3, 'ImgBed/manage.php', '图床', '本地在线图床 · 上传 / 外链 / 管理', 'administrator');
        self::ensureDir();

        return _t('图床已启用：后台「控制台 → 图床」，图片存于 ' . self::REL_DIR);
    }

    public static function deactivate()
    {
        Helper::removeAction('imgbed');
        Helper::removePanel(3, 'ImgBed/manage.php');
    }

    public static function config(Form $form)
    {
        $maxSize = new Text(
            'maxSizeMB',
            null,
            '20',
            _t('单张图片大小上限 (MB)'),
            _t('超过此大小的文件将被拒绝，默认 20')
        );
        $form->addInput($maxSize->addRule('isInteger', _t('请填写整数')));

        $publicAccess = new Radio(
            'listPublic',
            ['0' => _t('仅后台面板可浏览列表'), '1' => _t('公开列表页 (/imgbed.json 不推荐)')],
            '0',
            _t('图片列表接口'),
            _t('图片文件本身始终公开可访问，此项仅控制文件列表 API')
        );
        $form->addInput($publicAccess);
    }

    public static function personalConfig(Form $form)
    {
    }

    /* ---------- 供面板 / Action 使用的工具方法 ---------- */

    /** 存储绝对目录 */
    public static function absDir(): string
    {
        return rtrim(__TYPECHO_ROOT_DIR__, '/') . self::REL_DIR;
    }

    /** 存储目录对应的外链前缀（含站点地址） */
    public static function baseUrl(): string
    {
        $options = \Widget\Options::alloc();
        return rtrim($options->siteUrl, '/') . self::REL_DIR;
    }

    /** 大小上限（字节） */
    public static function maxBytes(): int
    {
        $cfg = \Widget\Options::alloc()->plugin('ImgBed');
        $mb  = max(1, (int) ($cfg->maxSizeMB ?? 20));
        return $mb * 1048576;
    }

    /** 列表接口是否公开 */
    public static function listPublic(): bool
    {
        $cfg = \Widget\Options::alloc()->plugin('ImgBed');
        return ($cfg->listPublic ?? '0') === '1';
    }

    public static function ensureDir(): void
    {
        if (!is_dir(self::absDir())) {
            @mkdir(self::absDir(), 0755, true);
        }
    }

    /** 扩展名是否允许 */
    public static function extAllowed(string $ext): bool
    {
        return in_array(strtolower($ext), self::ALLOWED_EXT, true);
    }
}
