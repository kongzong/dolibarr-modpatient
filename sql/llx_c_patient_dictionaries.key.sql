ALTER TABLE llx_c_patient_department ADD UNIQUE INDEX uk_c_patient_department_code (code);
ALTER TABLE llx_c_patient_id_type ADD UNIQUE INDEX uk_c_patient_id_type_code (code);
ALTER TABLE llx_c_patient_allergy_type ADD UNIQUE INDEX uk_c_patient_allergy_type_code (code);
ALTER TABLE llx_c_patient_doctor_title ADD UNIQUE INDEX uk_c_patient_doctor_title_code (code);
