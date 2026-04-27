# NewAPI-TAP

免费额度水龙头控制系统 —— 每月免费提供一定量的 token，每日平均分配，超量自动关水龙头。

## 工作原理

1. 设定每月免费 token 总量
2. 每天根据 **剩余总量 / 剩余天数** 计算当日可用额度
3. 每 5 分钟定时检查今日用量是否超过当日额度
4. 超过 → 关闭水龙头（移除 `free` 分组）
5. 未超过 → 开启水龙头（添加 `free` 分组）
6. 每天重新计算，已消耗的天数不再参与分配

## 渠道模式

系统支持四种渠道额度模式：

| 模式 | 配置值 | 说明 | 是否参与月度总额 | 是否控制开关 |
|------|--------|------|-----------------|-------------|
| 共享月度 | `shared` | 共享全局 `MONTHLY_TOKENS`，按剩余天数均分 | ✅ 是 | ✅ 是 |
| 独立月度 | `monthly` | 独立月度额度，按剩余天数均分 | ❌ 否 | ✅ 是 |
| 独立日额 | `daily` | 每日固定额度，次日自动重置 | ❌ 否 | ✅ 是 |
| 不限量 | `unlimited` | 仅监控用量和显示模型，不控制水龙头 | ❌ 否 | ❌ 否 |

**共享月度（shared）**：多个渠道共享同一个月度总额度池，适合统一管理的渠道。

**独立月度（monthly）**：渠道有独立的月度额度，按剩余天数均分，用完即关闸。不占用全局月度总额。

**独立日额（daily）**：渠道每天有固定额度，用完当天关闸，次日自动重置。不参与月度总额计算，适合需要每日固定免费额度的渠道。

**不限量（unlimited）**：渠道无额度限制，仅参与用量统计和模型显示，不会触发水龙头开关。适合需要监控但不需控制的渠道。

## 数据库架构

| 数据库 | 用途 | 表 |
|--------|------|-----|
| `newapi` | newapi 主库 | `logs`（读用量）, `channels`（写分组+读模型）, `abilities`（读写 free 组） |
| `newapi_tap` | TAP 自身数据 | `tap_state`, `tap_logs` |

`logs` 表关键字段：`id, user_id, created_at(Unix时间戳), prompt_tokens, completion_tokens, channel_id, ...`

用量统计：`SUM(prompt_tokens + completion_tokens)`，按 `channel_id IN (...)` 过滤，仅统计配置渠道的用量。

## 快速开始

### 1. 配置环境变量

复制 `.env.example` 为 `.env`，修改配置：

```bash
cp .env.example .env
```

编辑 `.env`：

```env
# 数据库配置
NEWAPI_DB_HOST=localhost
NEWAPI_DB_USER=newapi
NEWAPI_DB_PASS=your_password
NEWAPI_DB_NAME=newapi

TAP_DB_HOST=localhost
TAP_DB_USER=newapi_tap
TAP_DB_PASS=your_password
TAP_DB_NAME=newapi_tap

# 站点配置
SITE_NAME=MyAPI-TAP
API_SITE_URL=https://your-api-site.com

# 月度总额度（仅 shared 模式渠道使用）
MONTHLY_TOKENS=100000000

# 渠道配置（格式：渠道ID:模式:额度:开启分组:关闭分组）
TAP_CHANNELS=35:shared:0:default,vip,svip,free:default,vip,svip

# 访问密钥（建议设置，留空则不验证）
ACCESS_KEY=your-secret-key
```

> ⚠️ `.env` 文件已在 `.gitignore` 中排除，不会被提交到版本库。

### 2. 运行安装

```bash
php install.php
```

此脚本会：
- 自动创建 `newapi_tap` 数据库
- 创建 `tap_state` 状态表和 `tap_logs` 日志表
- 初始化默认状态
- 验证 newapi 数据库表和渠道是否存在

### 3. 设置定时任务

#### Linux (crontab)

```bash
*/5 * * * * php /path/to/newapi-tap/cron.php >> /path/to/newapi-tap/cron.log 2>&1
```

#### Windows (任务计划程序)

1. 打开「任务计划程序」
2. 创建基本任务 → 名称：`NewAPI-TAP`
3. 触发器：每天，每 5 分钟重复
4. 操作：启动程序 → `php.exe`，参数：`D:\Documents\newapi-tap\cron.php`

或使用 PowerShell：

```powershell
$action = New-ScheduledTaskAction -Execute "php.exe" -Argument "D:\Documents\newapi-tap\cron.php"
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 5)
Register-ScheduledTask -TaskName "NewAPI-TAP" -Action $action -Trigger $Trigger -Description "NewAPI免费额度水龙头控制"
```

### 4. 访问仪表盘

浏览器打开：`http://your-domain/newapi-tap/index.php`

## 文件说明

| 文件 | 说明 |
|------|------|
| `config.php` | 配置加载（从 .env 读取，数据库连接等） |
| `install.php` | 安装脚本（建库建表、初始化） |
| `cron.php` | 定时检查脚本（核心逻辑） |
| `index.php` | Web 仪表盘（状态展示） |
| `style.css` | 样式表 |
| `.env` | 环境配置（不入库） |
| `.env.example` | 环境配置模板 |

## 渠道配置详解

### 配置格式

**受控渠道**（shared / monthly / daily）：

```
TAP_CHANNELS=渠道ID:模式:额度:统计:开启分组:关闭分组
```

**不限量渠道**（unlimited）：

```
TAP_CHANNELS=渠道ID:unlimited:0:统计
```

多个渠道用分号 `;` 分隔。

### 配置示例

```env
# 渠道35: 共享月度，统计全部调用
# 渠道36: 独立月度，每月5000万token，仅统计免费调用
# 渠道37: 独立日额，每日200万token，统计全部调用
# 渠道38: 不限量，仅监控，统计全部调用
TAP_CHANNELS=35:shared:0:all:default,vip,svip,free:default,vip,svip;36:monthly:50000000:free:default,vip,free:default,vip;37:daily:2000000:all:default,free:default;38:unlimited:0:all
```

### 字段说明

| 字段 | 说明 |
|------|------|
| 渠道ID | newapi 中 `channels` 表的 `id` |
| 模式 | `shared` / `monthly` / `daily` / `unlimited` |
| 额度 | shared 填 0；monthly 填月度 token 数；daily 填每日 token 数；unlimited 填 0 |
| 统计 | `all` 统计全部调用；`free` 仅统计 free 组调用 |
| 开启分组 | 水龙头开启时 `channels.group` 的值（包含 `free`），unlimited 不需要 |
| 关闭分组 | 水龙头关闭时 `channels.group` 的值（不含 `free`），unlimited 不需要 |

## 核心算法

### shared / monthly 模式

```
月度剩余 = 月度总额 - 本月已消耗
今日额度 = 月度剩余 / 本月剩余天数（含今天）
今日剩余 = 今日额度 - 今日已消耗

如果 今日已消耗 >= 今日额度 → 关闭水龙头
如果 今日已消耗 <  今日额度 → 开启水龙头
```

**特点**：每天重新均分剩余额度，前期用多了后面天数额度自动减少，形成自平衡。

### daily 模式

```
今日额度 = 固定日额度（配置值）
今日剩余 = 今日额度 - 今日已消耗

如果 今日已消耗 >= 今日额度 → 关闭水龙头
如果 今日已消耗 <  今日额度 → 开启水龙头
```

**特点**：每天额度固定，次日自动重置，不参与月度总额计算。

## 水龙头开关机制

开水龙头时，系统会：
1. 更新 `channels` 表的 `group` 字段（设为开启分组，包含 `free`）
2. 读取渠道的 `models` 字段，为每个模型在 `abilities` 表插入/更新 `free` 组记录

关水龙头时，系统会：
1. 更新 `channels` 表的 `group` 字段（设为关闭分组，不含 `free`）
2. 删除 `abilities` 表中对应渠道的 `free` 组记录

分组同步时（状态未变但分组不一致），系统会：
1. 更新 `channels` 表的 `group` 字段
2. 根据当前状态添加或移除 `abilities` 记录
3. 记录 `tap_sync` 日志而非 `tap_open` / `tap_close`

## 注意事项

- `logs.created_at` 字段使用 Unix 时间戳（整数），与 newapi 标准一致
- 统计使用 `prompt_tokens + completion_tokens`，仅统计配置渠道（按 `channel_id` 过滤）
- 本系统统计所有用户在配置渠道的用量，统一切换分组
- 建议设置 `ACCESS_KEY` 防止仪表盘被未授权访问
- 日志默认保留 90 天，可在 `.env` 中修改 `LOG_RETENTION_DAYS`
- `.env` 文件包含敏感信息，已在 `.gitignore` 中排除，请勿提交到版本库
- 兼容 PHP 7.x+（不使用 `str_starts_with`、`putenv` 等受限函数）
