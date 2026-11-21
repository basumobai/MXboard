# Docker 安装与多实例部署

## 先准备好

需要一台 Linux 服务器、本机 Docker Engine + Compose **2.20+**、Git、`ss`（iproute2）和 `flock`（util-linux），以及**一个空 MySQL 数据库和它的独立账号**。宿主机不需要 PHP、Composer 或 Node.js。

- Docker 尚未安装：[官方安装文档](https://docs.docker.com/engine/install/)。
- MySQL 已在宝塔中运行：为每个面板新建一个空库和独立用户；地址通常是 `127.0.0.1`，端口可在安装时填写。
- 先准备面板完整网址（例如 `https://panel.example.com`）和管理员邮箱。
- 默认配置保留 Linux host 网络以兼容宿主机 MySQL；[隔离部署方案](docs/hardened-deployment.md) 另行迁移。不是 Docker Desktop / 远程 Docker 安装器，也不会替你安装或改动系统 Docker、MySQL、防火墙。

运行栈为 Laravel 13.39.0 / PHP 8.4.27，PHP 镜像使用 Alpine 3.24 并安装系统安全更新。依赖以 composer.lock 为准，更新需要重建镜像与重启 Web、Horizon、Scheduler，不能只替换源码。[安全升级记录](docs/security-upgrade-20261101.md) 列明上游 19 个提交、漏洞处置与验证边界。

## 安装只执行这三条命令

```bash
git clone https://github.com/basumobai/MXboard.git panel-a
cd panel-a
bash init.sh
```

按提示填写：实例名、Web 端口、Nginx 入口端口、Web/队列 Worker 数量、面板网址、数据库连接和管理员邮箱。其余构建、依赖安装、导入、视图预编译、服务启动和健康检查由脚本完成。

首次构建 PHP 镜像可能需要几分钟；端口默认从 6600、7001 开始寻找空闲值。密码输入不回显。结束时会显示**管理员初始密码、后台地址、反向代理目标**，请保存并及时更换初始密码。

不需要提前复制 `.env`，不需要手工改 Dockerfile、Compose 或 Nginx 文件。

## 同一台服务器再装一套

必须使用另一个目录、另一个实例名、不同的两个监听端口和独立数据库。可以在向导里填写，也可以预设非敏感部署参数：

```bash
git clone https://github.com/basumobai/MXboard.git panel-b
cd panel-b
COMPOSE_PROJECT_NAME=panel-b WEB_PORT=6601 GATEWAY_PORT=7002 WEB_WORKERS=2 bash init.sh
```

| 配置 | 第一套示例 | 第二套示例 | 含义 |
| --- | --- | --- | --- |
| 安装目录 | panel-a | panel-b | 源码、配置、日志、PID、vendor 分开 |
| COMPOSE_PROJECT_NAME | panel-a | panel-b | 容器、镜像及 Redis 卷的命名空间 |
| WEB_PORT | 6600 | 6601 | PHP 仅监听本机的端口 |
| GATEWAY_PORT | 7001 | 7002 | 宝塔反向代理应指向这个端口 |
| 数据库 | panel_a | panel_b | 独立库、独立授权用户 |
| WEB_WORKERS | 2 | 2 | 每个实例的 Web Worker 数量 |

安装器会将部署参数保存进 `.env`，以后在对应目录直接运行 `docker compose` 即可，不用每次重输。

Redis 继续使用每实例私有数据卷里的 Unix Socket，不开放 TCP 6379。即使每个容器内部都叫 `/data/redis.sock`，也不是同一个 Socket；安装器还会生成独立 Redis、缓存、Horizon 前缀和 Session Cookie 名。

**不要复制已安装目录的 `.env`，不要在安装后随意改实例名或使用另一个 `docker compose -p`。** 这可能创建新的 Redis 卷，导致旧队列暂时不可见。已有实例名或数据卷会让新安装器停止，不会自动接管或清空。

## 接上自己的域名和 HTTPS

在宝塔为面板域名新建站点并启用 HTTPS。反向代理目标填安装器最后显示的地址，例如：

```text
http://127.0.0.1:7002
```

不用代理到 Web 端口；Nginx 入口会直接提供静态资源。关闭代理缓存，完整保留 Authorization。手写 Nginx 时可使用下面的 location（端口按实际值替换）：

```nginx
location / {
    proxy_pass http://127.0.0.1:7002;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header Authorization $http_authorization;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $remote_addr;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Host $host;
    proxy_set_header X-Forwarded-Port $server_port;
}
```

外层代理覆盖客户端传入的 Forwarded Headers；不要直接信任其 X-Forwarded-For。若使用 Cloudflare 或其他代理，先按其实际来源地址配置可信 Real IP 链，再使用以上 `$remote_addr`。在 server 中设置 `client_max_body_size 32m;`，修改后先 `nginx -t` 再重载。不要公开 Web/Gateway、MySQL 或 Redis 端口。Cloudflare 如需使用，选择 Full (strict)，不要缓存后台、HTML、API 或订阅。

## 改端口或调整资源

在**该实例的** `.env` 中修改：

```dotenv
WEB_PORT=6601
GATEWAY_PORT=7002
WEB_WORKERS=2
WEB_MAX_REQUESTS=6600
HORIZON_MAX_PROCESSES=4
```

先确认新端口空闲，再执行：

```bash
docker compose config --quiet
docker compose up -d --wait --wait-timeout 180
```

端口和环境变量变更需要重建容器配置，单独 `docker compose restart` 不会应用新环境变量。如果改了 GATEWAY_PORT，也要修改宝塔的代理目标。既有安装未设置这些变量时，仍使用 6600/7001，不会擅自修改 Redis 前缀。

Web 默认 2 个 Worker，不再按整台宿主机 CPU 翻倍。队列默认固定 4 个工作进程：3 个处理订单、流量和统计，1 个处理邮件和 Telegram 通知，另有 Horizon 管理进程。各组按列出的队列顺序处理；设为 1 时所有队列共用一个工作进程。多个实例的 Worker 数量和内存开销会累加；先以小值运行，再根据队列积压、CPU 和内存调整，没有统一适用于所有服务器的最高性能配置。

## 这次性能改了什么

- 动态面板/主题配置按内容指纹缓存：内容不变时不再反复执行配置文件或强制失效 OPcache。每次读取仍检查内容变化，同一秒内等长修改、原子替换也能识别。
- 保留动态配置立即生效及路由变更的优雅重载，不启用会破坏该行为的 Laravel 配置/路由缓存。
- 保留 Composer 优化自动加载，安装和更新时预编译 Blade 视图。
- 默认禁用未经过本项目压测证明有益的 128 MB JIT 缓冲区；保留 OPcache 和时间戳检查。
- 修复 APP_ENV=production 没有 Horizon 队列配置的问题；队列规模按实例参数控制，不再探测整台主机内存决定进程数。
- 增加 HTTP 健康检查和容器日志轮转；保留数据库事务清理，异常事务回滚后断开连接，健康的常驻连接继续复用。

本轮逐级升级至 Laravel 13 / PHP 8.4，保留 AdapterMan、现有 API、流量账本和跨账期边界，修复原始支付正文与常驻 Worker 的兼容问题。已增加真实 MySQL 并发、HTTP/Horizon 和容器安全检查；尚未执行生产负载压测，不提供未经测量的 QPS 提升百分比。

## 已有站点更新

进入已部署的面板目录。先看工作区状态：

```bash
git status --short
```

没有输出时，直接更新：

```bash
bash update.sh
```

脚本会从当前分支的 `origin` 获取快进更新，自动备份源码、配置、主题、MySQL 和 Redis，然后重建并检查服务。更新期间网站会短暂不可用；不要先运行 `git pull`。

如果状态命令列出本地修改（包括未跟踪文件），先在维护窗口保存它们，再更新：

```bash
git stash push -u -m "before-mxboard-update"
bash update.sh
git stash list
```

改动会留在本机 stash 中。只在确认需要保留时，检查后再选择性恢复；不要把旧的 `public/index.php` 或其他文件未经比较直接覆盖到新版。发生冲突时保留现场并先核对，不要用 `reset --hard`、`git clean` 绕过检查。

更新后检查 `docker compose ps` 并登录面板验证。旧版更新器的首次升级、备份目录和完整恢复步骤见[安全更新与恢复](docs/safe-update.md)。

生产服务器只从 MXboard 的 `origin` 更新。Mundo-Connect/v2board 上游改动应在开发分支审查、移植并合入 MXboard 后再部署。

## 出错时看这里

```bash
docker compose ps
docker compose logs --tail=80 web gateway horizon scheduler
docker compose exec redis redis-cli -s /data/redis.sock ping
docker compose exec horizon php artisan horizon:status
```

| 情况 | 处理 |
| --- | --- |
| 端口占用、实例名冲突 | 选择未使用的端口/新实例名，不要结束未知进程或删除旧数据卷 |
| URL、邮箱、数据库密码错误或非空库 | 安装器在写入配置前停止；改正后可重跑，不要往已有业务库安装 |
| 已出现 `.env` 或 `config/v2board.php` | 先确认是否已经导入数据库；安装器不会重新执行建表，也不会删除配置 |
| 导入中途失败 | 保留配置和数据，检查数据库错误并从备份恢复或改用全新专用空库；不要直接删除 `.env` 重跑 |
| 已导入成功但启动失败 | 解决日志中的问题后运行下面的“恢复启动”，不重新执行安装器 |
| 修改配置似乎没生效 | 检查是否遗留 `bootstrap/cache/config.php`；不要执行 config:cache |
| 登录跳回或 Horizon 403 | 检查域名、Authorization 转发和 Redis；不要把环境改成 local 绕过权限 |

确认数据库导入和管理员创建**已成功**后的恢复启动：

```bash
docker compose up -d --wait redis
docker compose run --rm --no-deps installer php artisan config:clear
docker compose run --rm --no-deps installer php artisan view:cache
docker compose up -d --wait --wait-timeout 180
```

健康检查通过不等于所有业务都已验收。上线前实际验证：管理员登录、配置保存、节点新增/复制、节点流量上报、支付回调和邮件队列。分享日志前打码密码、JWT、订阅地址及节点 token。

**禁止作为日常操作执行：** `docker compose down -v`、`git reset --hard`、`php artisan config:cache`、`composer update`。前两项会丢失数据或本地修改，后两项会破坏动态配置或改变锁定依赖。
