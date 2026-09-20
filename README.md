# modPatient — Dolibarr 患者档案模块

Dolibarr 22.0.x 外部模块：面向中医馆/诊所的患者档案与就诊卡。零 core 修改。
医疗模块群（`custom/HEALTHCARE-MODULES-PLAN.md`）一期第一个模块，同时承担后续模块的公共库角色
（患者选择器、审计写入、"诊所"顶级菜单）。

当前版本：**0.1.0**（2026-09-20 四阶段全部验收通过，标签 `v0.1.0`）。规格见 [docs/spec-patient-v0.1.md](docs/spec-patient-v0.1.md)。

## 设计要点

- 患者 = **个人第三方**（`llx_societe.fk_typent = 8`），`llx_patient_profile` 以 `fk_soc` 唯一扩展
- 证件号三列：`dolEncrypt` 密文 + SHA-256 哈希（精确查找）+ 末 4 位（掩码展示）；明文不落表、不进日志、不进 REST
- 就诊卡号 `HZ-YYYYMM-NNNN`，InnoDB 流水表 + 行锁，取号与建档同一事务
- 权限四级 `read / write / profile / admin`；无 `profile` 时不查询病史与过敏表
- 审计表 `llx_patient_audit` 只插不删，停用模块保留
- 顶级菜单"诊所"（`mainmenu=clinic`），后续模块在其下挂左菜单
- 模块 ID `501600`（医疗群 501600–501660，每模块 +10）
- chinadiv 为可选依赖，运行时 `isModEnabled('chinadiv')` 检测

## 阶段状态

| 阶段 | 内容 | 状态 |
|---|---|---|
| 1 骨架 | descriptor、5 张表 + 4 字典（含种子）、权限、菜单、Tab 壳、Trigger、lib、测试 | 已完成（2026-09-20 测试 + UI 启用验证通过） |
| 2 建档与编号 | `PatientProfile` 类（第三方 + 卡号 + 档案同一事务）、`PatientCardNumbering`、建档/编辑/档案页、列表搜索、并发取号集成测试 | 代码完成，CLI 验证通过（19 单元 + 6 集成），UI 建档待验证 |
| 3 敏感数据与审计 | 过敏史 Tab（软删、重度警示、`findConflicts` 供处方拦截）、审计只读页、READ_PROFILE 留痕、无 profile 不查询病史 | 已完成（2026-09-20 CLI + UI 权限隔离验收通过） |
| 4 集成面 | 第三方卡片 Tab 一键建档（仅个人类型）、医生设置页、REST API、`patient_doctor_options` 选择器 | 已完成（2026-09-20 UI、REST 权限矩阵、停用→启用全流程验收通过） |

## 第三方卡片入口

已有的个人类型第三方（如 wecom 同步来的客户）在其卡片"患者档案"Tab 里可一键建档，
走 `PatientProfile::createForThirdparty()`：同一事务取号 + 插档案，拒绝公司类型与已有档案者。

## 医生设置

设置 → 患者模块 → 医生：把 Dolibarr 用户登记为医生并指定科室/职称（唯一键 fk_user，重复登记即更新）。
`patient_doctor_options($db)` 返回启用医生的 `fk_user => "姓名 (科室)"`，供后续模块的选择器使用。

## REST API

所有端点需 `DOLAPIKEY`；任何端点**不返回证件号明文、密文或哈希**，只返回掩码。

```
GET    /api/index.php/patient/patients?q=&limit=&page=&status=   # read；q 可为卡号/姓名/手机/完整证件号（哈希精确）
GET    /api/index.php/patient/patients/{id}                       # read；有 profile 权限时附带 history_note 与 allergies（写 READ_PROFILE 审计）
POST   /api/index.php/patient/patients                            # write；body 见类注释，id_number 入库即加密，不回显
GET    /api/index.php/patient/patients/{id}/allergies             # profile
POST   /api/index.php/patient/patients/{id}/allergies             # profile；{name, allergy_type, fk_product, severity, reaction}
DELETE /api/index.php/patient/patients/{id}/allergies/{aid}       # profile；软删
GET    /api/index.php/patient/doctors                             # read
```

## 建档流程

`PatientProfile::create()` 在一个事务里：`Societe::create()`（个人类型、客户、客户编码按系统规则自动）→
`PatientCardNumbering::nextReference()`（InnoDB 行锁，随外层事务提交/回滚）→ 插入档案 → `PATIENT_CREATE` 审计。
任一步失败整体回滚，不留孤儿第三方。事务外调用取号只做预览。

证件号：表单明文只在本次请求内存中；`setIdNumber()` 立即转为 dolEncrypt 密文 + SHA-256 哈希 + 末 4 位。
档案页默认掩码，有 `profile` 权限者点击"查看证件号"会写一条 `READ_IDNUMBER` 审计后显示一次。
列表搜索：卡号/姓名/手机模糊，证件号仅哈希精确。

## 过敏史与审计

- 过敏史在患者卡片"过敏史"Tab 维护，整页要求 `profile` 权限；可关联产品（开处方时精确拦截）或只记名称（名称包含匹配）。
  移除是软删（`status=0`），库内保留。重度过敏在档案页以红色警示展示。
- `PatientAllergy::findConflicts($fkPatient, $fkProduct, $label)` 是给 modPrescription 的拦截入口。
- 审计表 `llx_patient_audit` 只插不删：建档/改档/停用/过敏增删由 Trigger 写；打开档案页医疗区块、打开过敏页、
  查看证件明文由页面显式写 `READ_PROFILE` / `READ_IDNUMBER`。管理员在 设置 → 患者模块 → 审计日志 只读查看。
- 权限隔离：无 `profile` 的会话，档案页不查询过敏与病史表（不是隐藏），也看不到"过敏史"Tab。

## 安装

```
git clone https://github.com/kongzong/dolibarr-modpatient htdocs/custom/patient
```
Dolibarr → 设置 → 模块/应用 → 搜索 "Patient" → 启用。自动建表并载入字典种子
（科室/证件类型/过敏类型/职称，可在 设置 → 字典 维护）。

停用只移除常量/权限/菜单/Tab，不删任何表。

## 已知限制与待验证事项

- 编号流水表仅支持 MySQL/MariaDB（与 chinadoc 同限制）
- 证件号只支持哈希精确搜索，不做模糊；证件真实性只校验身份证校验位
- 单机构设计：表有 `entity` 列并按当前 entity 过滤，未做多公司共享策略
- 未做患者合并、家庭账户、批量导入；批量导入与药品目录导入一并留待二期
- chinadiv 为可选依赖：启用时档案页显示区划编码，级联选择器由 chinadiv 自己挂到地址字段
- 对患者发消息（wecom）不在本模块范围

## 测试

```
php tests/run_all.php                 # 结构 + 编号行为测试，无需数据库
php tests/integration/numbering.php   # 真实 MariaDB：20 并发建档取号、锁等待、回滚释放、缺表失败（隔离临时表）
```

## 开发约定

遵循 [custom/DOLIBARR-MODULE-DEVELOPMENT.md](../DOLIBARR-MODULE-DEVELOPMENT.md)；
环境与模块状态见 [custom/AGENTS.md](../AGENTS.md)。
