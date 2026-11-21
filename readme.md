<img src="https://avatars.githubusercontent.com/u/56885001?s=200&v=4" alt="Mundo logo" width="96" height="96" align="right"/>

# MXboard

基于 [Mundo-Connect/v2board](https://github.com/Mundo-Connect/v2board) 的 Docker 部署分支，适用于 Linux、Docker、MySQL 和 Mundo X 节点。

[完整安装与故障排查](./How%20to%20build.md) · [安全更新与恢复](./docs/safe-update.md) · [上游项目](https://github.com/Mundo-Connect/v2board)

## 安装

准备 Linux 服务器、Docker Engine + Compose 2.20+、Git 和一个空 MySQL 数据库。宿主机不需要安装 PHP 或 Composer。

```bash
git clone https://github.com/basumobai/MXboard.git mxboard
cd mxboard
bash init.sh
```

按提示填写实例、端口、网址、数据库和管理员邮箱。安装完成时保存显示的初始密码并及时修改。已有面板不要运行 `init.sh`。

## 更新已有面板

在面板目录运行：

```bash
git status --short
bash update.sh
```

工作区干净时，`bash update.sh` 就是日常更新命令。脚本会备份源码、MySQL 和 Redis，再更新并重启服务；更新期间网站会短暂不可用。不要先运行 `git pull`。

如果 `git status --short` 列出了文件（例如 `public/` 下的改动），先在维护窗口把本地改动收起来，再更新：

```bash
git stash push -u -m "before-mxboard-update"
bash update.sh
git stash list
```

`-u` 会一并保存未跟踪文件。改动仍保留在本机的 stash 中。需要保留自定义内容时，更新后先检查再选择性恢复；不要直接覆盖新版文件。遇到冲突先停下来核对，别用 `git restore`、`git reset --hard` 或 `git clean` 清理。

更新后可用 `docker compose ps` 检查服务。备份和恢复细节见[安全更新与恢复](./docs/safe-update.md)。

生产更新跟随 MXboard 的 `origin`。上游变更须先在开发分支审查并合入 MXboard，不要让生产服务器直接更新 Mundo-Connect/v2board。

## 注意

- 不要运行 `docker compose down -v`，这会删除 Redis 数据卷。
- 不要执行 `php artisan config:cache`，面板动态配置需要保持可重新载入。
- 不要公开 `.env`、数据库密码、管理员令牌或节点 Token。

<details>
<summary>私有仓库部署（高级）</summary>

### 服务器拉取与更新

仓库转为 Private 后，匿名 HTTPS `git clone` / `git pull` 无法继续使用。推荐给每台服务器配置**每仓库独立的只读 SSH Deploy Key**，不需要把 PAT 放进 README、Git URL 或环境配置。

1. 在需要访问此项目的服务器上用 root 运行：

```bash
ssh-keygen -t ed25519 -f /root/.ssh/github_mxboard -N '' -C 'mxboard-deploy-readonly'
cat /root/.ssh/github_mxboard.pub
```

2. 把输出的**公钥**加入对应私有仓库 `Settings → Deploy keys → Add deploy key`，不要勾选 `Allow write access`。私钥只保存在服务器（推荐权限 `0600`），不要复制到 GitHub 或公开文档。

3. 克隆并设置此项目专用的 SSH 身份：

```bash
GIT_SSH_COMMAND='ssh -i /root/.ssh/github_mxboard -o IdentitiesOnly=yes' \
  git clone git@github.com:basumobai/MXboard.git
cd MXboard
git config core.sshCommand 'ssh -i /root/.ssh/github_mxboard -o IdentitiesOnly=yes'
git fetch origin
```

已有 Git 工作树可先备份，再用 `git remote set-url origin git@github.com:basumobai/仓库名.git` 修改 origin，并设置上述 `core.sshCommand`。之后 `git pull` / `git fetch` 会自动使用只读 Deploy Key；GitHub SSH 主机指纹应按官方文档校验。部署密钥与应用内部的 API Key、节点 Token 是两种不同的凭证。

**Fork 迁移提醒：** 本仓库当前仍是公开 Fork。方案 B 的正确顺序为：先建立独立的 Private 仓库，完整镜像推送代码及分支、Tag；核验新仓库后将生产目录的 `origin` 改成实际新仓库地址。上方克隆地址只有在该原仓库本身可设为 Private 的情况下才适用；对于独立副本，请替换为新仓库名。`update.sh` 本身通过 `git fetch origin` 获取更新，只要部署密钥配置好即可继续工作。**不要在迁移前删除原 Fork，也不要在生产目录执行镜像覆盖命令。**

</details>
