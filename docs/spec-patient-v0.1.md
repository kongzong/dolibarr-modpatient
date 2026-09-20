# modPatient V0.1 规格 — 患者档案与就诊卡

> 状态：**评审稿**（2026-09-20）。医疗模块群一期第一个模块。
> 立项前置问题已回答（2026-09-20）：中医馆/中西医结合、单机构、药品目录暂无数据源、非医保定点纯自费。
> 上位规划：`custom/HEALTHCARE-MODULES-PLAN.md`；技术约定：`custom/DOLIBARR-MODULE-DEVELOPMENT.md`。

## 0. 立项答案带来的调整（相对规划稿）

| 答案 | 调整 |
|---|---|
| 中医馆 / 中西医结合 | 本模块不受影响；辨证/饮片进 modMedRecord / modPrescription 一期 |
| 单机构 | 所有自建表保留 `entity` 列并按 `$conf->entity` 过滤，不做跨 entity 共享策略 |
| 药品目录未定 | 本模块不依赖产品表；过敏史的 `fk_product` 为可选关联 |
| 非医保、纯自费 | 无医保号字段；证件类型字典不含医保卡 |

## 1. 定位与已核实地基

患者 = **个人第三方**（`llx_societe.fk_typent = 8`，`TE_PRIVATE`），模块表 `llx_patient_profile` 以 `fk_soc` 唯一扩展医疗属性。
理由：发票、付款、日程、wecom 外部联系人映射都挂第三方，后续模块零迁移。

本机 22.0.4 源码已核实（2026-09-20）：

| 依赖 | 出处 | 结论 |
|---|---|---|
| 加密/解密 | `core/lib/security.lib.php` `dolEncrypt()` / `dolDecrypt()`，密钥为 `instance_unique_id` | 证件号密文存模块表；密文不可查询，另存 SHA-256 哈希列用于精确查找 |
| 个人类型 | `install/mysql/data/llx_c_typent.sql` id 8 `TE_PRIVATE` | 建档时 `Societe::create()` 并设 `typent_id = 8` |
| 个人联系人 | `Societe::create_individual()`（societe.class.php:1195） | 在已建第三方下生成同名联系人；是否调用在阶段 2 按 UI 行为验证 |
| 字典机制 | `modulebuilder/template` `$this->dictionaries`（tabname/tabsql/tabfield…） | 科室、证件类型、过敏类型走标准字典，免写管理页 |
| 权限一级形式 | `wecom` `rights[$r][4]='read'` | 沿用一级形式（DEV.md §二.3） |
| 第三方 Tab | `wecom` `tabs[] = 'thirdparty:+wecom:...'` | 同形式加 `thirdparty:+patient` |
| 顶级菜单 | `wecom` `menu` `type=top` + `fk_menu='fk_mainmenu=wecom'` | 新建顶级菜单 `clinic`，后续模块挂其下 |
| 流水取号 | `chinadoc/class/chinadocshipmentnumbering.class.php` `nextReference($prefix)`：InnoDB 表 + `FOR UPDATE` + `transaction_opened` 判断 | 复制为 `PatientCardNumbering`，前缀按月 |
| 地址编码 | `chinadiv/lib/chinadiv.lib.php` `chinadiv_get_soc_codes($fkSoc)`；Trigger `COMPANY_CREATE/MODIFY` 自动落库 | 患者地址不重复实现，仅在档案页展示编码；chinadiv 为**可选**依赖 |

## 2. 功能范围（做）

### 2.1 数据

- `llx_patient_profile`：`rowid, entity, fk_soc(唯一), card_no(唯一), id_type(字典code), id_number_enc(dolEncrypt密文), id_number_hash(SHA-256, 索引), id_number_tail(末4位明文, 展示用), gender(M/F/U), birth_date, blood_type(可空), phone_alt, emergency_name, emergency_phone, history_note(既往史), note_private, status(1正常/0停用), fk_user_creat, fk_user_modif, date_creation, tms`
- `llx_patient_allergy`：`rowid, entity, fk_patient, allergy_type(字典code: DRUG/FOOD/OTHER), fk_product(可空), name(必填), severity(1轻/2中/3重), reaction(备注), status, date_creation, fk_user_creat`
- `llx_patient_doctor`：`rowid, entity, fk_user(唯一), fk_department(字典), title(职称字典code), status`
- `llx_patient_card_sequence`：`ref_prefix PK, last_value`（同 chinadoc，InnoDB，停用不删）
- `llx_patient_audit`：`rowid, entity, fk_patient, action(READ_PROFILE/CREATE/UPDATE/READ_IDNUMBER/ALLERGY_ADD/ALLERGY_DEL/DISABLE), fk_user, ip, detail(JSON 文本), date_creation`；**只插不改不删**，模块内无删除入口
- 字典（`llx_c_patient_*`，随模块 `dictionaries` 声明）：`department`（科室）、`id_type`（证件类型：身份证/护照/港澳台通行证/其他）、`allergy_type`、`doctor_title`（职称）

### 2.2 编号

- 就诊卡号 `HZ-YYYYMM-NNNN`，按月流水，全库跨 entity 单序列（与 chinadoc 一致）
- 取号在患者 create 事务内执行：`begin → Societe::create → nextReference → INSERT profile → commit`；任一步失败整体回滚，不留孤儿第三方
- 事务外调用只做预览，不占号

### 2.3 页面

- 顶级菜单 **诊所**（`mainmenu=clinic`）；左菜单：患者列表、新建患者、医生设置（admin）
- **建档表单**：姓名、性别、出生日期、手机、证件类型/号码、地址（原生字段，chinadiv 启用时自动出现级联）、过敏史（可多行）、既往史。一步创建第三方 + 档案 + 发卡号
- **患者列表**：按卡号 / 姓名 / 手机 / 证件号（哈希精确）搜索；分页遵循 DEV.md §二.6（`print_barre_liste` 传总数、GET 表单前置、页码带 limit）
- **患者档案页**（`patient/card.php?id=`）：基本信息、卡号、证件掩码（`****1234`）、过敏史列表（红色高亮重度）、既往史；有 `profile` 权限者可点击"查看证件号"（写审计 READ_IDNUMBER）
- **第三方卡片 Tab "患者档案"**：非患者第三方显示"建立患者档案"按钮，已是患者则跳转档案页
- **医生设置页**（admin）：用户 ↔ 科室/职称维护

### 2.4 权限（一级形式）

| 权限 | 含义 | 典型角色 |
|---|---|---|
| `read` | 看列表、卡号、基本信息、证件掩码 | 挂号员 |
| `write` | 建档、改基本信息 | 挂号员 |
| `profile` | 看/改过敏史、既往史、证件明文 | 医生、护士 |
| `admin` | 医生设置、字典、停用档案 | 管理员 |

页面与 REST 每个入口独立检查；无 `profile` 权限时档案页不渲染过敏/既往史区块（不是隐藏，是不查询）。

### 2.5 审计（Trigger + 显式写入）

- 模块 Trigger `interface_99_modPatient_PatientTriggers`：监听自定义动作 `PATIENT_CREATE / PATIENT_MODIFY / PATIENT_DISABLE / PATIENT_ALLERGY_ADD / PATIENT_ALLERGY_DELETE`，写 `llx_patient_audit`
- 读操作（打开档案页、查看证件明文）不经 Trigger，由页面在渲染前显式写 `READ_PROFILE / READ_IDNUMBER`
- 审计只读页（admin）：按患者/用户/时间过滤

### 2.6 库与 API

- `lib/patient.lib.php`：`patient_get_by_soc($fkSoc)`、`patient_get_by_card($cardNo)`、`patient_mask_id($tail)`、`patient_hash_id($number)`、`patient_audit($fkPatient, $action, $detail)`、`patient_select_html($name, $selected)`（供后续模块复用的患者选择器）
- REST（`class/api_patient.class.php` → 类名 `Patient`）：
  - `GET /patient/patients?q=`（read；返回不含证件明文）
  - `GET /patient/patients/{id}`（read；`profile` 权限才附带 allergies/history）
  - `POST /patient/patients`（write；一步建档）
  - `GET /patient/patients/{id}/allergies`（profile）
  - `POST /patient/patients/{id}/allergies`（profile）
  - 任何端点**永不返回证件明文**，REST 不提供查看证件明文入口

### 2.7 与 chinadiv 的关系

- descriptor 不声明 `depends`；运行时 `isModEnabled('chinadiv')` 为真则档案页显示区划编码（调用 `chinadiv_get_soc_codes`）
- 地址落库由 chinadiv 自己的 `COMPANY_CREATE/MODIFY` Trigger 完成，本模块不重复实现

## 3. 明确不做（V0.1）

- 患者合并 / 家庭账户 / 电子健康卡 / 会员积分
- 医生排班（modClinicBook）、就诊记录（modMedRecord）
- 证件号 OCR、身份证校验位以外的真实性核验（只做 18 位校验位与出生日期一致性提示）
- 患者头像、附件
- 证件号模糊搜索（只支持精确哈希）
- 多 entity 共享策略
- 批量导入患者（留待药品目录导入一起评估）
- PostgreSQL 支持（流水表与 chinadoc 同限制）

## 4. 红线（继承规划 §4 + 本模块）

1. 证件号：`dolEncrypt` 密文 + 哈希 + 末 4 位，明文**不落任何表、不进日志、不进 REST 响应、不进 syslog**；页面默认掩码
2. 审计表只插不删；模块 `remove()` 不删审计与流水表；停用不删业务表
3. 细粒度权限：无 `profile` 权限的会话**不查询**病史与过敏表
4. 零 core 修改；所有 Dolibarr API 先 grep 本机源码
5. 取号与建档同一事务；失败完整回滚，不返回空号或重复号
6. 建档时向患者发送任何消息（wecom）不在本模块范围；后续模块接入前须用户确认
7. Trigger 中不得抛未捕获异常阻断核心第三方保存（返回 -1 并写 syslog）

## 5. 阶段划分（每阶段可人工验证）

| 阶段 | 交付 | 人工验证点 |
|---|---|---|
| 1 骨架 | descriptor（ID 501600）、5 张表 + 4 字典 SQL、权限、菜单、语言文件、`tests/run_all.php` 骨架 | UI 启用/停用成功；字典页可见可编辑；菜单"诊所"出现 |
| 2 建档与编号 | `PatientProfile` 类、`PatientCardNumbering`、建档表单、列表、档案页基本区 | 建档一步成功；并发测试 20 次不重号；失败回滚无孤儿第三方 |
| 3 敏感数据与审计 | 证件加密/哈希/掩码、过敏史 CRUD、审计 Trigger 与读审计、权限隔离 | 无 profile 权限看不到病史；查看明文留痕；证件哈希搜索命中 |
| 4 集成面 | 第三方 Tab、医生设置、REST API、chinadiv 联动展示、README、docs | REST 权限矩阵全拒；停用→启用全流程；测试全过后打 v0.1.0 |

## 6. 验收标准

- A. 干净库 UI 启用 → 建档 → 停用 → 启用，档案与卡号无损
- B. 并发建档（CLI 模拟 20 并发）卡号唯一且连续，无孤儿 `llx_societe` 行
- C. 无 `profile` 权限用户：档案页无过敏/既往史 DOM，REST `GET /patients/{id}` 无 allergies 字段
- D. 证件号：库内 `id_number_enc` 非明文；`grep` 日志目录与 REST 响应无该号码；哈希精确搜索命中
- E. 审计：建档、改档、查看明文各产生一条记录；模块内无删除入口；`remove()` 后表仍在
- F. `tests/run_all.php` 全过，含结构断言：类命名、每个 API 方法含 `hasRight`、`id_number` 明文不出现在 API 类与日志调用中
- G. 关闭 chinadiv 时档案页正常；开启时显示区划编码
- H. README 与 descriptor 版本一致，AGENTS.md 模块状态更新

## 7. 模块标识

- 目录 `htdocs/custom/patient/`，类 `modPatient`，常量 `MAIN_MODULE_PATIENT`
- 模块 ID **501600**（医疗群段 501600–501660，每模块 +10）
- 权限 ID `50160011 / 21 / 31 / 41`（read/write/profile/admin）
- 语言文件 `langs/zh_CN/patient.lang`、`langs/en_US/patient.lang`
- 仓库 `github.com/kongzong/dolibarr-modpatient`（首次推送前由维护者创建）
