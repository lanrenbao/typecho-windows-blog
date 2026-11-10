# Typecho Windows 本地服务器一键部署方案

在 **Windows 10/11 普通家用电脑**上部署一套生产可用的 Typecho 博客服务器，通过 **Cloudflare Tunnel** 获得固定域名 + 免费 HTTPS 公网访问 —— **不需要公网 IP、不需要 IPv6、不依赖路由器端口映射**，家宽在运营商大内网（CGNAT）后面也能用。

> 本方案在一台 i5-4460 / 8GB 内存 / Windows 10 的机器上实测跑通，全套服务常驻内存约 95MB。

## 架构

```
访客 ──HTTPS──> Cloudflare 边缘（自动签发证书）
                    │
              Cloudflare Tunnel（cloudflared，出站隧道，免公网IP）
                    │
              Caddy :8080（反代 / gzip / 安全规则）
                    │
              php-cgi ×3 FastCGI :9001-9003（负载均衡）
                    │
              Typecho 1.x + SQLite（免装 MySQL）
```

| 组件 | 版本参考 | 作用 |
|---|---|---|
| PHP | 8.1 NTS（官方 windows.php.net 包） | 运行 Typecho |
| php-cgi ×3 | 随 PHP | FastCGI 进程池，规避 Windows 单进程串行 |
| Caddy | 2.x（官方单文件二进制） | Web 服务器 / 反代 |
| Typecho | 1.2+（官方 Release zip） | 博客程序 |
| SQLite | PHP 自带 pdo_sqlite | 数据库，零额外内存 |
| pm2 | npm 全局安装 | 进程守护 + 开机自启 |
| cloudflared | Cloudflare 官方客户端 | 内网穿透，固定域名 + 免费 HTTPS |

## 目录结构（部署后）

```
D:\server\
├─ php\                  PHP 8.1 + php.ini + cacert.pem
├─ caddy\                Caddy 单文件二进制
├─ www\                  Typecho 站点根目录
│  └─ usr\typecho.db     SQLite 数据库（务必定期备份！）
├─ cloudflared\          隧道客户端 + config.yml
├─ tools\                siteUrl 同步 / 健康检查脚本
├─ logs\                 各组件日志
├─ backups\              数据库备份（不放网站目录内，防止被下载）
├─ Caddyfile             Caddy 配置
├─ ecosystem.config.js   pm2 进程定义
└─ start-server.cmd      一键启动（幂等）
```

## 快速开始

### 前置要求

- Windows 10/11，**管理员权限** PowerShell
- 已安装 [Node.js](https://nodejs.org/)（供 npm 安装 pm2）
- 一个域名，且已托管到 [Cloudflare](https://dash.cloudflare.com/)（免费计划即可）
  - 托管方法：Cloudflare 控制台 → Add a site → 输入域名 → 选 Free → 到域名注册商处把 NS 改成 Cloudflare 给的两条地址 → 等后台显示 **Active**

### 一键安装

```powershell
# 管理员 PowerShell 中运行
cd typecho-windows-blog
.\install.ps1 -Domain blog.example.com -AdminPassword "你的强密码"
```

脚本会自动完成：

1. 下载 PHP 8.1 NTS / Typecho / Caddy / CA 证书（全部官方源）
2. 生成 php.ini、Caddyfile、pm2 进程定义
3. `npm install -g pm2` 并以 pm2 启动 php-cgi ×3 + Caddy
4. Typecho 命令行全自动安装（SQLite，无需点表单）
5. 注册 Windows 计划任务（开机自启 / IP 同步 / 健康自愈）

> 脚本中途会调用 `cloudflared tunnel login` 弹出浏览器 —— 登录 Cloudflare 并选中你的域名点 **Authorize**。

### 绑定域名（Cloudflare Tunnel）

```powershell
cd D:\server\cloudflared
.\cloudflared.exe tunnel create typecho
.\cloudflared.exe tunnel route dns typecho blog.example.com
```

编辑 `D:\server\cloudflared\config.yml`：

```yaml
tunnel: <create 命令输出的 UUID>
credentials-file: C:\Users\<你>\.cloudflared\<UUID>.json

ingress:
  - hostname: blog.example.com
    service: http://127.0.0.1:8080
  - service: http_status:404
```

重启隧道并固化站点地址：

```powershell
pm2 restart cf-tunnel
"blog.example.com" | Out-File -Encoding ascii D:\server\tools\tunnel-host.txt
D:\server\php\php.exe D:\server\tools\sync-siteurl.php
```

完成。浏览器打开 `https://blog.example.com` 即为你的博客，后台在 `/admin/`，HTTPS 证书由 Cloudflare 自动签发续期。

## 备用公网方案（无域名时）

| 方案 | 前提 | 说明 |
|---|---|---|
| Cloudflare Quick Tunnel | 无 | `cloudflared tunnel --url http://127.0.0.1:8080`，5 分钟拿到 `https://xxx.trycloudflare.com`，**地址随机且重启会变** |
| IPv6 直连 | 光猫放行 IPv6 入站 | 家宽 IPv6 是真公网；`tools/sync-siteurl.php` 会自动用本机稳定 IPv6 更新站点地址 |
| 路由器端口映射 | 宽带有真公网 IPv4 | 光猫 WAN 口 IP 若为 `10.x`/`100.64.x`/`172.16-31.x` 即 CGNAT，映射无效，打运营商客服申请公网 IP |

`tools/sync-siteurl.php` 的地址优先级（每 10 分钟由计划任务执行一次）：

```
1. Cloudflare 隧道域名（tools/tunnel-host.txt）
2. Quick Tunnel 随机域名（从日志解析）
3. 本机网卡上的真公网 IPv4
4. CGNAT 时回退本机稳定 IPv6（自动剔除 24h 轮换的临时地址）
```

## 安全设计

Caddy 已内置以下规则（见 `configs/Caddyfile`）：

- `*.db / *.sql / *.log / *.ini / *.bak`、隐藏文件（`.git` 等）→ **404**
- `/config.inc.php`、`/install.php` → **404**（装完自动防重装）
- `/usr/` 下仅放行 `themes/`、`plugins/`、`uploads/`，其余（数据库、缓存）→ 404
- 响应头去掉 `Server`，加 `nosniff` / `SAMEORIGIN` / `Referrer-Policy`

另外强烈建议：

- **不要开路由器 DMZ**（等于把全部端口捅到公网）
- 开启 Windows 防火墙三个配置文件，只放行 8080 入站：
  ```powershell
  Set-NetFirewallProfile -Profile Domain,Private,Public -Enabled True
  New-NetFirewallRule -DisplayName 'Blog-HTTP-8080' -Direction Inbound -Protocol TCP -LocalPort 8080 -Action Allow -Profile Any
  ```
- 关闭已淘汰的 SMB1：`Set-SmbServerConfiguration -EnableSMB1Protocol $false -Force`

## 关键坑与解法（实测踩过）

| 坑 | 现象 | 解法 |
|---|---|---|
| Caddy 改写 `X-Forwarded-Proto` | HTTPS 下后台登录死循环 | Caddyfile 加 `servers { trusted_proxies static 127.0.0.1/32 ::1/128 }` |
| `session.save_path` 未配置 | 后台登录令牌校验失败 | php.ini 指定可写目录，如 `D:\server\tmp\sessions` |
| HTTPS 出站证书校验失败 | PHP/curl 请求外网 API 报错 | php.ini 配 `curl.cainfo` / `openssl.cafile`，下载 [cacert.pem](https://curl.se/ca/cacert.pem) |
| Windows 版 `net_get_interfaces()` | 只枚举出虚拟网卡 | `sync-siteurl.php` 改为解析 `ipconfig` 输出 |
| 从 PHP 调 PowerShell | 被安全策略静默拦截 | 一律改用 `ipconfig` / `tasklist` 等纯命令 |
| 数据库备份放站点目录 | `.db.bak` 可被公网直接下载 | 备份移到站点目录外（`D:\server\backups\`） |
| pm2 挂在终端会话下 | 终端关闭进程全灭 | 注册计划任务（登录触发）拉起 `start-server.cmd` |
| Caddyfile 正则 | Go RE2 不支持 `(?!...)` 负向前瞻 | 用 `not path` 白名单写法 |

## 计划任务（自愈与自动化）

| 任务名 | 触发 | 作用 |
|---|---|---|
| BlogServer | 用户登录时 | 拉起整套服务栈（pm2 startOrRestart） |
| BlogSiteUrlSync | 每 10 分钟 | 公网地址变化时自动更新 Typecho siteUrl |
| BlogHealthCheck | 每 5 分钟 | 首页异常时自动重启服务栈 |

> 计划任务是「登录触发」，机器重启后需登录一次桌面；若需无人值守开机自启，可改用 `SYSTEM` 账户注册或使用 [WinSW](https://github.com/winsw/winsw) 把 pm2 包成 Windows 服务。

## 日常运维

```bat
pm2 list                          :: 查看服务状态
pm2 logs cf-tunnel --lines 20     :: 隧道日志
pm2 restart all                   :: 全部重启
D:\server\start-server.cmd        :: 一键启动（幂等）
type D:\server\tools\current-url.txt  :: 当前生效的公网地址
```

数据库备份（建议每周 + 发文后）：

```bat
copy /y D:\server\www\usr\typecho.db D:\server\backups\typecho-%date:~0,4%%date:~5,2%%date:~8,2%.db
```

## 已知限制

1. Windows 版 php-cgi 单进程串行，靠 3 进程轮询，并发上限约 3 个同时执行的 PHP 请求 —— 博客足够，高并发请换 Linux + php-fpm
2. TLS 在 Cloudflare 边缘终结，局域网内仍是 http 明文
3. Quick Tunnel 模式地址随机；配好固定域名后不再有此问题
4. Cloudflare 免费隧道不适合超大文件下载/长传场景，博客图文完全够用
5. 计划任务登录触发，重启后需登录桌面一次

## License

MIT
