# NewAPI-TAP

免费额度水龙头控制系统 —— 每月免费提供一定量的 token，每日平均分配，超量自动关水龙头。

## 工作原理

1. 设定每月免费 token 总量
2. 每天根据 **剩余总量 / 剩余天数** 计算当日可用额度
3. 每 5 分钟定时检查今日用量是否超过当日额度
4. 超过 → 关闭水龙头（移除 `free` 分组）
5. 未超过 → 开启水龙头（添加 `free` 分组）
6. 每天重新计算，已消耗的天数不再参与分配

## 数据库架构

| 数据库 | 用途 | 表 |
|--------|------|-----|
| `newapi` | newapi 主库 | `logs`（读用量）, `channels`（写分组+读模型）, `abilities`（读写 free 组） |
| `newapi_tap` | TAP 自身数据 | `tap_state`, `tap_logs` |

`logs` 表关键字段：`id, user_id, created_at(Unix时间戳), prompt_tokens, completion_tokens, channel_id, ...`

用量统计：`SUM(prompt_tokens + completion_tokens)`，按 `channel_id IN (...)` 过滤，仅统计配置渠道的用量。

## 快速开始

### 1. 修改配置

编辑 `config.php`：

```php
// 每月免费 token 额度
$monthly_tokens = 100000000; // 1亿 token

// 渠道配置
$tap_channels = [
    [
        'channel_id'    => 35,
        'open_groups'   => 'default,vip,svip,free',
        'closed_groups' => 'default,vip,svip',
        'name'          => '免费渠道 #35',
    ],
];

// 访问密钥（建议设置）
$access_key = 'your-secret-key';
```

### 2. 运行安装

```bash
php install.php
```

此脚本会：
- 自动创建 `newapi_tap` 数据库
- 创建 `tap_state` 状态表
- 创建 `tap_logs` 日志表
- 初始化默认状态
- 验证 `quota_data` 表和渠道是否存在

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
| `config.php` | 配置文件（数据库、额度、渠道等） |
| `install.php` | 安装脚本（建库建表、初始化） |
| `cron.php` | 定时检查脚本（核心逻辑） |
| `index.php` | Web 仪表盘（状态展示） |

## 核心算法

```
月度剩余 = 月度总 token - 本月已消耗 token
今日额度 = 月度剩余 / 本月剩余天数（含今天）
今日剩余 = 今日额度 - 今日已消耗

如果 今日已消耗 >= 今日额度 → 关闭水龙头
如果 今日已消耗 <  今日额度 → 开启水龙头
```

**特点**：每天重新均分剩余额度，前期用多了后面天数额度自动减少，形成自平衡。

## 扩展多渠道

在 `config.php` 的 `$tap_channels` 数组中添加：

```php
$tap_channels = [
    [
        'channel_id'    => 35,
        'open_groups'   => 'default,vip,svip,free',
        'closed_groups' => 'default,vip,svip',
        'name'          => 'GPT-4 免费渠道',
    ],
    [
        'channel_id'    => 42,
        'open_groups'   => 'default,vip,free',
        'closed_groups' => 'default,vip',
        'name'          => 'Claude 免费渠道',
    ],
];
```

开水龙头时，系统会：
1. 更新 `channels` 表的 `group` 字段（添加 `free`）
2. 读取渠道的 `models` 字段，为每个模型在 `abilities` 表插入 `free` 组记录

关水龙头时，系统会：
1. 更新 `channels` 表的 `group` 字段（移除 `free`）
2. 删除 `abilities` 表中对应渠道的 `free` 组记录

`abilities` 表插入参数可在渠道配置中调整（`abilities_enabled`, `abilities_priority`, `abilities_weight`, `abilities_tag`）。

## 注意事项

- `logs.created_at` 字段使用 Unix 时间戳（整数），与 newapi 标准一致
- 统计使用 `prompt_tokens + completion_tokens`，仅统计配置渠道（按 `channel_id` 过滤）
- 本系统统计所有用户在配置渠道的用量，统一切换分组
- 建议设置 `$access_key` 防止仪表盘被未授权访问
- 日志默认保留 90 天，可在配置中修改
