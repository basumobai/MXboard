# 已有面板安全更新

本流程用于本仓库的 Docker Compose 部署，需要本机 Docker、Git、flock、tar、sha256sum 和可连接的 MySQL TCP 数据库。更新会短暂停站；保留现有 `.env`、APP_KEY、Compose 项目名、端口、数据库、Redis 卷、面板设置、主题设置和未被 Git 跟踪的自定义资源。不要运行首次安装器，也不要删除卷。

本版本包含 Laravel 8→13、PHP 8.4.27 / Alpine 3.24 和依赖生命周期升级，必须完整重建并重启常驻 PHP 进程。
升级说明和公告处置见 [security-upgrade-20261101.md](security-upgrade-20261101.md)。
可选的隔离网络迁移见 [hardened-deployment.md](hardened-deployment.md)，应单独验证宿主机 MySQL 连通后安排维护点。

## 更新

更新脚本从当前分支同名的 `origin` 分支获取代码，并且只接受快进更新。部署实例的 `origin` 应指向 MXboard 或你维护的兼容镜像；Mundo-Connect/v2board 上游应在开发工作副本中单独检查，不能直接作为生产更新源。不要先运行 `git pull`。

### 更新前检查

在面板目录执行：

```bash
git remote get-url origin
git branch --show-current
git status --short
docker compose config --quiet
```

确认 `origin` 与分支正确，工作区干净，Compose 配置有效，并留出足够磁盘空间。脚本会拒绝未提交修改、detached HEAD、缺少同名远端分支和非快进更新；不要用 `git reset --hard`、`git clean` 或删除数据卷来绕过检查。

### 旧版脚本首次升级

如果现有 `update.sh` 还没有自动备份能力，先从当前分支读取并执行远端的新脚本：

```bash
set -euo pipefail
branch=$(git branch --show-current)
test -n "$branch"
git fetch origin "$branch"
git show FETCH_HEAD:update.sh | bash -s -- --project-dir "$PWD"
```

务必先确认 `origin` 指向部署仓库，而不是上游。引导命令不会先切换工作树；新版脚本会再检查状态、获取提交并按下述流程备份。成功后，日常更新只需运行：

```bash
bash update.sh
```

备份默认位于项目目录旁的 `mundo-backups/`。可指定另一个项目外的备份目录：

```bash
UPDATE_BACKUP_ROOT=/srv/backups/mxboard bash update.sh
```

## 自动备份与失败处理

新版脚本按以下顺序执行，备份未完成前不会切换源码：

1. 获取并校验目标提交、工作区和 Compose 配置；在停站前准备 MySQL 备份工具。
2. 启动 Redis 后停止 gateway、web、scheduler，等待旧 `traffic_fetch` 和 `stat` 队列排空（最多 10 分钟），然后停止 Horizon。脚本只控制当前 Compose 实例；如果其他程序也写入同一数据库，应一起安排维护。
3. 在停写状态下保存源码、配置、主题和 `storage/`，导出 MySQL，并在 Redis 保存后归档当前实例的数据卷。
4. 校验归档，写入 `manifest.txt`、`SHA256SUMS` 和 `COMPLETE`。只有这一步完成后才执行快进更新、重建镜像、安装锁定依赖、迁移和健康检查。

默认备份目录为项目目录旁的 `mundo-backups/`；自定义的 `UPDATE_BACKUP_ROOT` 也必须位于项目之外。目录默认仅当前系统用户可读写，不要放进 `public/` 或公开下载目录。备份包括 `project.tar.gz`、`database.sql`、`redis.tar.gz`、`manifest.txt`、`SHA256SUMS` 和 `COMPLETE`。源码归档排除根目录的 `.git`、`vendor`、`node_modules`；依赖可根据归档里的锁文件重新安装。

自动备份使用应用的有效数据库配置，通过权限受限的临时文件向 MySQL 客户端提供凭据；脚本随后删除临时凭据文件，不把密码写入命令参数或日志。MySQL 备份用户必须有导出数据库、触发器、例程和事件所需权限，数据库需使用 TCP 连接。条件不满足时更新会停止。备份目录需要有足够空间。

### 失败时脚本如何处理

- 备份或切换源码前失败：脚本保留备份目录，并尝试重新启动旧服务。
- 源码切换后失败：脚本不会自动倒退源码、数据库或 Redis，也不会启动旧 Worker；先保存现场日志，再决定修复迁移或完整恢复。
- 队列超过 10 分钟仍未排空：更新停止；先查 Horizon 和队列积压原因，避免丢失旧流量任务。

恢复必须使用同一次快照中的源码、MySQL 和 Redis。不要只回滚 MySQL，也不要把不同时间点的 Redis 数据卷与数据库混用。

## 故障排查

### 管理界面空白、静态资源返回 403

如果后台只显示“跳到主要内容”或白屏，先检查 CSS 和 JavaScript 是否能被 Nginx 读取：

```bash
curl -I https://你的面板域名/assets/admin/custom.css
curl -I https://你的面板域名/assets/admin/umi.js
stat -c '%a %U:%G %n' public/assets/admin/custom.css public/assets/admin/umi.js
namei -l public/assets/admin/custom.css
```

如果这两个资源返回 403，而同目录其他资源返回 200，通常是文件或上级目录缺少 Nginx 读取/遍历权限。进入项目目录后修复公开目录权限：

```bash
find public -type d -exec chmod u+rwx,go+rx {} +
find public -type f -exec chmod u+rw,go+r {} +
```

这只调整 `public/` 的权限，不改文件内容；不要使用 `chmod -R 777`。再执行上面的 `curl -I`，两个资源应返回 200。新版 `update.sh` 会在检查未提交改动前和切换源码后都修复该权限问题，因此本地改动不会再阻止这个修复。

## 恢复

先检查 `COMPLETE` 并在备份目录执行 `sha256sum -c SHA256SUMS`。保留失败现场，停止 gateway/web/horizon/scheduler/redis。恢复必须将**源码、MySQL 和 Redis 同一份快照一起恢复**，不要只回滚数据库后重放新队列。

推荐在新的私有项目目录解压 `project.tar.gz`，按 `manifest.txt` 的 old_commit 恢复 Git 源码并检查配置；原 `.env` 中的 Compose 项目名必须保留。通过 MySQL 客户端将 `database.sql` 导入原数据库（或先导入隔离库验证）。凭据使用权限 600 的客户端配置文件，避免在命令行中填写密码。将 `redis.tar.gz` 恢复到该实例原 Redis 卷之前，先保存失败后的卷，再清空该卷中的旧文件，避免遗留 AOF 混入快照。

按恢复的 composer.lock 安装依赖，执行 config:clear 和 view:cache，最后启动服务并检查健康状态、用户余额/流量、节点和主题。恢复是维护者的显式操作，脚本不会自动删除数据库或 Redis 数据。涉及流量账本的升级不要跳过数据库校验，也不要通过重新安装来恢复已有站点。

## 样式更新

自定义 CSS/JS 与管理端 Umi bundle 都使用内容哈希作为 URL 版本，但管理端覆盖层和 Umi bundle 使用**彼此独立的缓存键**。修改 CSS/自定义 JS 不会再让浏览器重新下载数 MB 的 Umi bundle；只有 `public/assets/admin/umi.js` 本身变化时才更新它的 URL。CDN 必须把查询参数计入缓存键，首页/后台 HTML 不应缓存为永久静态页面。更新后普通刷新即可获取发生变化的资源；已打开的标签页需要刷新。本版本不会强制中断正在编辑的表单。现有主题配置不会重置，新安装的默认顶栏使用浅色。既有站点若仍是深色用户顶栏，可在主题配置中将“顶部风格”选为“亮”。
