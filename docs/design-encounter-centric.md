# 诊所业务数据模型与 UI 设计（定稿）

> 状态：设计定稿，待实施
> 关联：`spec-patient-v0.1.md`；患者详情页 Tab 重构讨论
> 范围：本文档仅含最终结论与落地清单，不含方案权衡与讨论过程

## 1. 业务模型（三场景）

| 场景 | 患者 | 诊疗记录(medrecord) | 处方 | 发药 | 收费 | 扣卡 |
|---|---|---|---|---|---|---|
| 散客买非处方药(OTC) | 不建档 | 不进系统 | — | — | — | — |
| 买处方药 | 建档 | 不建 | 挂患者(`fk_patient`) | 挂处方(`fk_prescription`) | bill 主表挂患者；bill_line 明细挂处方+发药 | card_log 挂 bill，经 bill→处方溯源 |
| 诊疗 | 建档 | 建 | 挂诊疗记录+患者(`fk_medrecord`+`fk_patient`) | 挂处方 | bill 主表挂患者+诊疗；bill_line 明细挂处方+发药 | 同上 |

流程：
- **OTC**：不进系统，系统只管理"有患者"的业务。
- **处方药**：建档 → 开方（不建 medrecord）→ 发药（凭处方）→ 收费（bill 主表挂患者，明细行挂处方/发药）→ 扣卡（card_log 挂 bill，经 `bill_line→prescription` 反查处方）。
- **诊疗**：建档 → 建 medrecord（诊疗记录，含诊断）→ 开方（挂 medrecord）→ 发药（凭处方）→ 收费（bill 主表挂患者+medrecord，明细行挂处方/发药）→ 扣卡（同上）。

两场景差异仅在：诊疗场景建 medrecord 且 bill 主表额外挂它；处方药场景不建 medrecord，bill 主表仅挂患者。

## 2. 核心概念

- **medrecord = 就诊 = 病历（单表合并方案，零新表）**：`llx_medrecord` 一身兼两职——既有就诊壳属性（`visit_date`/`fk_doctor`），又有临床内容（主诉/诊断/治则/医嘱）。建诊疗记录时一并写病历内容。
- **诊疗记录与病历是一回事**：合并方案下为同一张 medrecord 记录的两种视角，不区分、不拆表。
- **患者级（跨就诊持久）**：患者档案、过敏史、次卡（卡=资产）。
- **就诊级（某次诊疗发生）**：诊疗、处方、发药、收费、次卡消费（扣卡动作）。
- **次卡资产 vs 消费**：购卡=患者级钱包操作（不建就诊，走 `card_log` op=CREATE，无 `fk_bill`）；扣卡=就诊级，经 `card_log.fk_bill → bill → bill_line.fk_prescription` 溯源到处方。

## 3. 数据模型与改造清单

### 3.1 各表与就诊(medrecord)的关联（现状 + 需补）
| 表 | 与就诊关联 | 本轮变更 |
|---|---|---|
| `llx_medrecord` | 自身即就诊 | 无 |
| `llx_prescription` | `fk_medrecord` 可空 | 无（已是"挂患者、可空挂就诊"） |
| `llx_pharmacy_dispense` | 经 `fk_prescription` 间接关联 | 无（`fk_prescription NOT NULL` 保证发药必属处方） |
| `llx_clinicpay_bill` | 仅有 `fk_patient` | **加 `fk_medrecord` integer DEFAULT NULL** |
| `llx_clinicpay_bill_line` | `fk_prescription`/`fk_dispense` 可空未用 | **启用回填**（开单时记来源） |
| `llx_clinicpay_card` | 钱包资产（患者级） | 无 |
| `llx_clinicpay_card_log` | 经 `fk_bill → bill → bill_line` 反查 | 无 |

### 3.2 需执行的 DDL
```sql
ALTER TABLE llx_clinicpay_bill
  ADD COLUMN fk_medrecord integer DEFAULT NULL AFTER fk_patient,
  ADD INDEX idx_fk_medrecord (fk_medrecord);
```

### 3.3 可空性规则（关键，已确认）
- `prescription.fk_medrecord` 可空：处方药（无诊疗）合法，处方直接挂患者；诊疗场景回填。
- `pharmacy_dispense.fk_prescription` NOT NULL（不可改）：发药必基于处方。
- `bill.fk_medrecord` 可空：诊疗收费填本就诊；处方药/纯销售/充值留空。
- medrecord 临床字段允许为空（续方/抓药/纯缴费的到店照建就诊，临床字段留空）。

### 3.4 收费与扣卡溯源（精确）
- **收费单主表 `clinicpay_bill`**：`fk_patient` 必填；诊疗场景额外填 `fk_medrecord`，处方药场景留空。
- **收费明细 `clinicpay_bill_line`**：启用 `fk_prescription` / `fk_dispense`，使"这笔钱对应哪张处方的哪些药"可溯源。字段已存在，仅需开单时回填。
- **扣卡 `clinicpay_card_log`**：挂 `fk_bill`（收费单）。溯源路径 `card_log.fk_bill → bill_line.fk_prescription → prescription`。当前 card_log **无 `fk_prescription` 列**，先靠反查即可；若需一步直挂可后续加列，非必需。
- **纯充值**：card_log op=RECHARGE，`fk_bill = NULL`，不建 bill/medrecord，零假数据。

## 4. 就诊(medrecord)创建规则（结论）
- **仅"诊疗"场景由医生创建** medrecord（开始写诊疗记录时建，处方再挂它）。
- **处方药场景不创建** medrecord（处方 `fk_medrecord = NULL`，直接挂患者）。
- **OTC 不进系统**，无建档、无记录。
- **购卡（次卡充值）不创建** medrecord（患者级钱包操作）。
- 同一次到店多份病历**不归并**，医生自行合并成主病历后再开方/发药；系统每条独立成行。

## 5. UI 形态
### 5.1 患者详情页顶层 Tab（最终，选项甲）
`患者档案(Societe原生) / 概览(Dashboard) / 过敏史 / 就诊列表(=现有medrecord Tab) / 处方 / 用药记录 / 收费 / 次卡`

- **患者级 Tab（处方/用药记录/收费/次卡）保留**，作为"按患者"的全量视角，各有入口（现状 `patient:+prescription` / `+dispensing` / `+clinicpay_bills` / `+clinicpay_cards`）。
- **就诊列表**即现有 `patient:+medrecord` Tab，定位为就诊(medrecord)列表——按就诊聚合视角。
- 两个维度正交：**患者级 Tab = 该患者全部 X（全量）**；**就诊列表 = 按某次诊疗筛选的 X（聚合）**。互为索引，不重复不遗漏。
- 患者级 Tab 中每条记录若有 `fk_medrecord`，标注"所属就诊"并链接到就诊列表对应项。

### 5.2 就诊列表（现有 `patient:+medrecord` Tab 定位）
- 实为 medrecord 列表（medrecord=就诊），按 `visit_date` 倒序，每行：日期 · 医生 · 主诉摘要 · 本次合计 · 状态
- 顶部"新建就诊"按钮（=新建 medrecord）
- 同次到店多份病历各自成行
- 点开某条 medrecord → 进入单次就诊详情页（§5.3）

### 5.3 单次就诊详情页（增强现有 `medrecord/patient_tab.php?id=MR`）
- 就诊信息条：日期 / 医生 / 主诉 / 类型（有诊断 / 纯缴费 / 纯收费）
- 纵向分区（visit thread）：
  1. 诊疗：本次 medrecord（诊断/治则/医嘱），可多份
  2. 处方：本次处方 + 发药状态
  3. 发药：本次发药/退药
  4. 收费：本次账单 + 支付方式（含次卡抵扣）
  5. 本次扣卡：card_log 中 fk_bill 属本就诊的部分
- 各区"在该次就诊下新建"按钮自动回填 `fk_medrecord`
- **次卡区块只显示本次扣卡**；"名下全部次卡"在独立的次卡 Tab（患者级）

### 5.4 概览 Dashboard
- 保留：过敏警示 + 4 摘要卡（最近就诊/在用处方/待缴费/有效次卡）+ 快捷入口 + 审计时间线
- "最近就诊"进就诊列表；"待缴费/有效次卡"按患者聚合（患者级指标）

## 6. 存量数据迁移（实施阶段交付）
1. `prescription`：已有 `fk_medrecord` 保持；历史无 medrecord 的处方按 `date_presc` 归并/补建就诊壳（若需）
2. `pharmacy_dispense`：经 `fk_prescription → prescription.fk_medrecord` 反查（天然可溯）
3. `clinicpay_bill`：回填 `fk_medrecord`——有 `bill_line.fk_prescription/dispense` 的归属对应就诊；纯手工账单按 `date_creation` 建"无病历就诊"或留空
4. 脚本事务包裹 + 回滚预案，先测试库验证再上生产

## 7. 实施步骤
1. `clinicpay_bill` 加 `fk_medrecord` 列（§3.2 DDL）
2. 各模块写操作回填 `fk_medrecord`（开方/收费用药场景；诊疗场景显式填）
3. 启用 `bill_line.fk_prescription/fk_dispense` 回填
4. 迁移脚本（事务 + 回滚）
5. 增强 `patient:+medrecord` Tab 为就诊列表 + 增强 `medrecord/patient_tab.php` 显示单次诊疗 thread（无新建页面文件）
6. lint + 真库探针 + （尽力）web 验证

## 8. 实施状态（代码已落地）
- **DDL/类/API**：`clinicpay_bill.fk_medrecord` 已加；`paybill` 类与 `api_clinicpay` 已支持读写；`bill_line.fk_prescription/fk_dispense` 已由 `snapshotLines`/`replaceLines` 落库。
- **UI 联动（发药单→收费）**：`pharmacy/card.php` 已发药状态显示「收费」入口，链 `clinicpay/bill.php?fk_dispense`；`clinicpay/bill.php` 接收 `fk_dispense` 后自动预填收费行（含 `fk_prescription`/`fk_dispense` 行级溯源）并据处方回填 `fk_medrecord`，顶部显示「关联发药单」横幅；防重复收费（查 `clinicpay_bill_line.fk_dispense`）。
- **Web 渲染验证**受环境 CSRF / 缺 playwright 限制未做（环境已知缺口，非代码问题）。
