-- modPatient dictionary seed rows for a TCM / integrated clinic.
-- Idempotent on re-enable: fixed rowid + unique code, run_sql accepts
-- DB_ERROR_RECORD_ALREADY_EXISTS. Labels are editable in the dictionary UI.

INSERT INTO llx_c_patient_department (rowid, pos, code, label, active) VALUES (1, 10, 'TCM_INT',   '中医内科', 1);
INSERT INTO llx_c_patient_department (rowid, pos, code, label, active) VALUES (2, 20, 'ACU_TUINA', '针灸推拿科', 1);
INSERT INTO llx_c_patient_department (rowid, pos, code, label, active) VALUES (3, 30, 'TCM_GYN',   '中医妇科', 1);
INSERT INTO llx_c_patient_department (rowid, pos, code, label, active) VALUES (4, 40, 'TCM_PED',   '中医儿科', 1);
INSERT INTO llx_c_patient_department (rowid, pos, code, label, active) VALUES (5, 50, 'REHAB',     '康复理疗科', 1);
INSERT INTO llx_c_patient_department (rowid, pos, code, label, active) VALUES (6, 60, 'GP',        '全科（西医）', 1);

INSERT INTO llx_c_patient_id_type (rowid, pos, code, label, active) VALUES (1, 10, 'IDCARD',   '居民身份证', 1);
INSERT INTO llx_c_patient_id_type (rowid, pos, code, label, active) VALUES (2, 20, 'PASSPORT', '护照', 1);
INSERT INTO llx_c_patient_id_type (rowid, pos, code, label, active) VALUES (3, 30, 'HMT',      '港澳台居民通行证/居住证', 1);
INSERT INTO llx_c_patient_id_type (rowid, pos, code, label, active) VALUES (4, 40, 'OTHER',    '其他', 1);

INSERT INTO llx_c_patient_allergy_type (rowid, pos, code, label, active) VALUES (1, 10, 'DRUG',  '药物', 1);
INSERT INTO llx_c_patient_allergy_type (rowid, pos, code, label, active) VALUES (2, 20, 'FOOD',  '食物', 1);
INSERT INTO llx_c_patient_allergy_type (rowid, pos, code, label, active) VALUES (3, 30, 'OTHER', '其他', 1);

INSERT INTO llx_c_patient_doctor_title (rowid, pos, code, label, active) VALUES (1, 10, 'CHIEF',     '主任医师', 1);
INSERT INTO llx_c_patient_doctor_title (rowid, pos, code, label, active) VALUES (2, 20, 'DEPUTY',    '副主任医师', 1);
INSERT INTO llx_c_patient_doctor_title (rowid, pos, code, label, active) VALUES (3, 30, 'ATTENDING', '主治医师', 1);
INSERT INTO llx_c_patient_doctor_title (rowid, pos, code, label, active) VALUES (4, 40, 'RESIDENT',  '医师', 1);
INSERT INTO llx_c_patient_doctor_title (rowid, pos, code, label, active) VALUES (5, 50, 'ASSISTANT', '医士', 1);
