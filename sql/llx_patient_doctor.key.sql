ALTER TABLE llx_patient_doctor ADD UNIQUE INDEX uk_patient_doctor_user (fk_user);
ALTER TABLE llx_patient_doctor ADD INDEX idx_patient_doctor_department (fk_department);
